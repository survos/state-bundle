<?php
declare(strict_types=1);

namespace Survos\StateBundle;

use Survos\CoreBundle\Traits\HasAssetMapperTrait;
use Survos\StateBundle\Attribute\Transition;
use Survos\StateBundle\Command\DumpWorkflowPhpCommand;
use Survos\StateBundle\Command\DumpWorkflowsYamlCommand;
use Survos\StateBundle\Command\IterateCommand;
use Survos\StateBundle\Command\MakeWorkflowCommand;
use Survos\StateBundle\Command\StateQueuesDumpCommand;
use Survos\StateBundle\Command\VizCommand;
use Survos\StateBundle\Compiler\RegisterWorkflowEntitiesPass;
use Survos\StateBundle\Compiler\StatePrependExtension;
use Survos\StateBundle\Controller\WorkflowController;
use Survos\StateBundle\Controller\WorkflowDashboardController;
use Survos\StateBundle\Doctrine\PostLoadSetEnabledTransitionsListener;
use Survos\StateBundle\Doctrine\TransitionListener;
use Survos\StateBundle\Messenger\Middleware\AsyncQueueRoutingMiddleware;
use Survos\StateBundle\Service\AsyncQueueLocator;
use Survos\StateBundle\Service\ConfigureFromAttributesService;
use Survos\StateBundle\Service\EntityInterfaceDetector;
use Survos\StateBundle\Service\PrimaryKeyLocator;
use Survos\StateBundle\Service\WorkflowHelperService;
use Survos\StateBundle\Service\WorkflowListener;
use Survos\StateBundle\Service\WorkflowStatsService;
use Survos\StateBundle\Traits\EasyMarkingTrait;
use Survos\StateBundle\Traits\MarkingInterface;
use Survos\StateBundle\Twig\WorkflowExtension;
use Symfony\Bundle\FrameworkBundle\Command\WorkflowDumpCommand;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Messenger\MessageBusInterface;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_locator;

final class SurvosStateBundle extends AbstractBundle implements CompilerPassInterface
{
    use HasAssetMapperTrait;

    public function getAlias(): string
    {
        // matches "survos_state:" in consumer apps
        return 'survos_state';
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass($this);
//        $container->addCompilerPass(new RegisterWorkflowEntitiesPass());
    }

    public function process(ContainerBuilder $container): void
    {

        // Find all services that implement the interface
//        foreach ($container->getDefinitions() as $id => $definition) {
//            $class = $definition->getClass();
//            if ($class && is_subclass_of($class, MarkingInterface::class)) {
//                dump($class, $definition);
//                $definition->addTag('state.marking_interface');
//                break;
//            }
//        }

        // Pull the fully-processed Framework configuration
        $frameworkExt = $container->getExtension('framework');
        $rawFramework = $container->getExtensionConfig('framework');
        $configuration = $frameworkExt->getConfiguration($rawFramework, $container);
        $processed = (new Processor())->processConfiguration($configuration, $rawFramework);

        $workflowConfig = $processed['workflows']['workflows'] ?? [];
        $container->setParameter('workflows.configuration', $workflowConfig);


        $container->findDefinition(WorkflowHelperService::class)
            ->setArgument('$configuration', $workflowConfig);

        $container->findDefinition(VizCommand::class)
            ->setArgument('$workflows', tagged_iterator('workflow'));

        // Provide the map to the locator if parameter is present (set in prepend)
        if ($container->hasParameter('survos_state.async_transition_map')
            && $container->hasDefinition(AsyncQueueLocator::class)) {
            $def = $container->findDefinition(AsyncQueueLocator::class);
            $def->setArgument('$map', $container->getParameter('survos_state.async_transition_map'));
        }
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // 1) Register middleware + core services
        $builder->autowire(AsyncQueueRoutingMiddleware::class)->setAutoconfigured(true)->setPublic(false);
        $builder->autowire(PrimaryKeyLocator::class)->setAutoconfigured(true)->setPublic(false);

        // 2) Ensure middleware runs on default bus BEFORE send_message
        if (($config['enable_dynamic_routing'] ?? true) === true) {
            $builder->prependExtensionConfig('framework', [
                'messenger' => [
                    'buses' => [
                        'messenger.bus.default' => [
                            'middleware' => [
                                AsyncQueueRoutingMiddleware::class,
                            ],
                        ],
                    ],
                ],
            ]);
        }

        foreach ([AsyncQueueLocator::class,
                     WorkflowStatsService::class,
                     EntityInterfaceDetector::class,
                     WorkflowHelperService::class] as $class) {
            $builder->autowire($class)
                ->setPublic(true)
                ->setAutowired(true)
                ->setAutoconfigured(true);
        }

        foreach ([
            WorkflowController::class,
            WorkflowDashboardController::class] as $class) {
            $builder->autowire($class)
                ->setPublic(true)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->addTag('controller.service_arguments');
        }

        foreach ([IterateCommand::class,
                     DumpWorkflowPhpCommand::class,
                     DumpWorkflowsYamlCommand::class,
                     MakeWorkflowCommand::class,
                     VizCommand::class,
                     StateQueuesDumpCommand::class,
                 ] as $commandClass) {
            if (class_exists($commandClass)) {
                $builder->autowire($commandClass)->setAutoconfigured(true)->addTag('console.command');
            }
        }

        $builder->setParameter('survos_workflow.base_layout', $config['base_layout'] ?? 'base.html.twig');

        // Optional: expose Symfony Workflow dump/viz via tagged locator (if the commands are present)
        if (class_exists(WorkflowDumpCommand::class)) {
            $container->services()
                ->set('console.command.survos_workflow_dump', WorkflowDumpCommand::class)
                ->args([tagged_locator('workflow', 'name')]);
        }

        $builder->autowire(WorkflowExtension::class)
            ->addArgument(new \Symfony\Component\DependencyInjection\Reference(WorkflowHelperService::class))
            ->addTag('twig.extension');

        $builder->autowire(ConfigureFromAttributesService::class)->setAutoconfigured(true)->setPublic(true);
//        $builder->autowire(TransitionListener::class)->setAutoconfigured(true)->setPublic(true);
//        $builder->autowire(PostLoadSetEnabledTransitionsListener::class)->setAutoconfigured(true)->setPublic(true);

        // Workflow listener wiring
        $builder->autowire(WorkflowListener::class)
            ->setArgument('$workflowHelperService', new \Symfony\Component\DependencyInjection\Reference(WorkflowHelperService::class))
            ->setArgument('$messageBus', new \Symfony\Component\DependencyInjection\Reference(MessageBusInterface::class))
            ->addTag('kernel.event_listener', ['event' => 'workflow.completed', 'method' => 'onCompleted'])
            ->addTag('kernel.event_listener', ['event' => 'workflow.entered', 'method' => 'onEntered']);

        $builder->setParameter('survos_state.entity_paths', $config['workflow_paths'] ?? ['%kernel.project_dir%/src/Workflow']);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            // Prefix is only used for non-Doctrine brokers. Empty by default.
            ->scalarNode('queue_prefix')->defaultValue('')->end()
            ->scalarNode('base_layout')->defaultValue('base.html.twig')->end()
            ->booleanNode('enable_dynamic_routing')->defaultValue(true)->end()
            ->arrayNode('workflow_paths')->prototype('scalar')->end()
            ->defaultValue(['%kernel.project_dir%/src/Workflow'])->end()
            ->scalarNode('async_transport_dsn')->defaultValue('doctrine://default')->end()
            ->end();
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // keep this small: asset-mapper + delegate heavy work
        if ($this->isAssetMapperAvailable($builder)) {
            $dir = realpath(__DIR__ . '/../assets/');
            if ($dir && file_exists($dir)) {
                $builder->prependExtensionConfig('framework', [
                    'asset_mapper' => [
                        'paths' => [
                            $dir => '@survos/state',
                        ],
                    ],
                ]);
            }
        }

        // Delegate to compile-time builder
        StatePrependExtension::prepend($container, $builder, $this->getAlias());
    }
}
