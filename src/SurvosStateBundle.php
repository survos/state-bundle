<?php

namespace Survos\StateBundle;

use JetBrains\PhpStorm\NoReturn;
use Survos\CoreBundle\Traits\HasAssetMapperTrait;
use Survos\StateBundle\Command\DumpWorkflowPhpCommand;
use Survos\StateBundle\Command\DumpWorkflowsYamlCommand;
use Survos\StateBundle\Command\IterateCommand;
use Survos\StateBundle\Command\MakeWorkflowCommand;
use Survos\StateBundle\Command\SurvosWorkflowConfigureCommand;
use Survos\StateBundle\Command\SurvosWorkflowDumpCommand;
use Survos\StateBundle\Command\VizCommand;
use Survos\StateBundle\Compiler\BuildAsyncQueuesFromWorkflowsPass;
use Survos\StateBundle\Config\AttributesWorkflowConfigBuilder;
use Survos\StateBundle\Controller\WorkflowController;
use Survos\StateBundle\Doctrine\TransitionListener;
use Survos\StateBundle\Messenger\Middleware\AsyncQueueRoutingMiddleware;
use Survos\StateBundle\Service\AsyncQueueLocator;
use Survos\StateBundle\Service\ConfigureFromAttributesService;
use Survos\StateBundle\Service\PrimaryKeyLocator;
use Survos\StateBundle\Service\SurvosGraphVizDumper3;
use Survos\StateBundle\Service\WorkflowHelperService;
use Survos\StateBundle\Service\WorkflowListener;
use Survos\StateBundle\Twig\WorkflowExtension;
use Survos\StateBundle\Messenger\Middleware\DynamicRoutingMiddleware;
use Survos\StateBundle\Attribute\Workflow;
use Symfony\Bundle\FrameworkBundle\Command\WorkflowDumpCommand;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Workflow\Registry;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_locator;

class SurvosStateBundle extends AbstractBundle implements CompilerPassInterface
{
    use HasAssetMapperTrait;

    public function getAlias(): string
    {
        return 'survos_workflow';
    }
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Register this class as a pass, to eliminate the need for the extra DI class
        // https://stackoverflow.com/questions/73814467/how-do-i-add-a-twig-global-from-a-bundle-config
//        $container->addCompilerPass(new BuildPrimaryKeyMapPass());
        $container->addCompilerPass($this);
        // it's here now, but we should probably refactor
//        $container->addCompilerPass(new BuildAsyncQueuesFromWorkflowsPass());
    }

    public function process(ContainerBuilder $container): void
    {
        // Pull the fully-processed Framework configuration
        $frameworkExt = $container->getExtension('framework');
        $rawConfigs   = $container->getExtensionConfig('framework');
        $configuration = $frameworkExt->getConfiguration($rawConfigs, $container);
        $processed     = (new Processor())->processConfiguration($configuration, $rawConfigs);

        $wf = $processed['workflows']['workflows'] ?? [];
        if (!$wf) {
            $container->setParameter('survos_state.async_transition_queues', []);
            return;
        }

        // Get the queue prefix from SurvosStateBundle config (already stored as a parameter by your Extension)
//        $prefix = (string) ($container->getParameter('survos_state.queue_prefix') ?? '');
        $prefix = 'pokemon'; // @todo: get from config
        if ($prefix !== '' && !str_ends_with($prefix, '.')) {
            $prefix .= '.';
        }

        $map = []; // transition => "<prefix><transition>"


        // Symfony stores transitions as arrays; metadata is under each transition item
        foreach ($wf as $wfName => $wfDef) {
            $transitions = $wfDef['transitions'] ?? [];
            foreach ($transitions as $t) {
                // Name can be under 'name' or the array key; prefer explicit 'name'
                $name = $t['name'] ?? null;
                if (!$name) {
                    // Symfony also allows associative definition; handle safely
                    // (If there is no name, skip—async tagging requires a stable transition name)
                    continue;
                }
                $meta = $t['metadata'] ?? [];

                // Rule: async when metadata['async'] === true
                // (transport metadata may also exist; queue name is still prefix + transition)
                $isAsync = ($meta['async'] ?? false) === true;
                if (!$isAsync) {
                    continue;
                }

                $map[$name] = $prefix . $name;
            }
        }

        $container->setParameter('survos_state.async_transition_queues', $map);

        $definition = $container->findDefinition(AsyncQueueLocator::class);
        $definition->setArgument('$transitionToQueueMap', $map);


        $this->process2($container);
    }

    public function process2(ContainerBuilder $container): void
    {
        // this is a mess and should be refactored.  Older versions of Symfony did not provide easy access to workflows, but this is no longer the case.
        $workflows = $container->findTaggedServiceIds('workflows');
        // for now, look for workflows defined in config/packages/workflow.php


        $configs = $container->getExtensionConfig('framework');

        $configuration = $container
            ->getExtension('framework')
            ->getConfiguration($configs, $container)
        ;

        $config = (new Processor())->processConfiguration($configuration, $configs);
        $workflowConfig = $config['workflows']['workflows'] ?? [];
        $container->setParameter('workflows.configuration', $workflowConfig);

//        dd($workflowConfig, $config, $configs, $configuration);
        // set enabled transitions from the database.
        $transitionListenerDefinition = $container->findDefinition(TransitionListener::class);
        $transitionListenerDefinition->setArgument('$workflowHelperService', new Reference(WorkflowHelperService::class));
        $transitionListenerDefinition->setArgument('$workflows', tagged_iterator('workflow'));

        $workflowHelperDefinition = $container->findDefinition(WorkflowHelperService::class);
        $workflowHelperDefinition->setArgument('$configuration', $workflowConfig);
//        $workflowHelperDefinition->setArgument('$workflows', tagged_iterator('workflow'));

        $container->findDefinition(SurvosWorkflowDumpCommand::class)
            ->setArgument('$workflows', tagged_iterator('workflow'));
        $container->findDefinition(VizCommand::class)
            ->setArgument('$workflows', tagged_iterator('workflow'));



//        foreach (tagged_iterator('workflow', 'name') as $x) {
//            dd(__METHOD__, __CLASS__, $x);
//        }
//        dd(tagged_iterator('workflow'));


    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // 1) Register the middleware service
        $builder->autowire(AsyncQueueRoutingMiddleware::class)
            ->setAutoconfigured(true)
            ->setPublic(false);

        $builder->autowire(PrimaryKeyLocator::class)
            ->setAutoconfigured(true)
            ->setPublic(false)
//            ->setArgument('$em', '%doctrine%')
        ;

// 2) Ensure it runs on the default bus BEFORE send_message
        if ($config['enable_dynamic_routing']) {
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



// (Optional) If you prefer the tagged style instead, you can keep:
        if (0) // if the way above doesn't work.
        $builder->autowire(AsyncQueueRoutingMiddleware::class)
            ->setAutoconfigured(true)
            ->setPublic(false)
            ->addTag('messenger.middleware', ['alias' => 'async_queue_routing', 'priority' => 200]);
// …but the bus-level prepend above is the most deterministic for order.



        foreach ([AsyncQueueLocator::class, WorkflowHelperService::class] as $class) {
            $builder->autowire( $class)
                ->setPublic(true)
                ->setAutowired(true)
                ->setAutoconfigured(true)
            ;
        }

        foreach ([IterateCommand::class,
                     DumpWorkflowPhpCommand::class,
                     DumpWorkflowsYamlCommand::class,
                     MakeWorkflowCommand::class, VizCommand::class] as $commandClass) {
            $builder->autowire($commandClass)
                ->setAutoconfigured(true)
                ->addTag('console.command')
            ;



            $builder->setParameter('survos_workflow.base_layout', $config['base_layout']);

        // this probably isn't needed anymore
        $container->services()
            ->set('console.command.survos_workflow_dump', WorkflowDumpCommand::class)
            ->args([
                tagged_locator('workflow', 'name'),
            ]);

        // do we need this?
        $container->services()
            ->set('console.command.survos_workflow_viz', VizCommand::class)
            ->args([
                tagged_locator('workflow', 'name'),
            ]);

//        $builder->autowire(VizCommand::class)
//            ->setAutoconfigured(true)
//            ->setPublic(true)
//            ->setAutowired(true)
////            ->setArgument('$messageHandlers', tagged_locator('kernel.event', 'name')
//            ->addTag('console.command')
//        ;

        //        $builder->register('workflow.registry', Registry::class); // isn't this already done by Symfony/Workflow

        //        $builder->register('survos_workflow_bundle.workflow_helper', WorkflowHelperService::class);

//        $workflowHelperId = 'survos_workflow_bundle.workflow_helper';
//        $container->services()->alias($workflowHelperId, WorkflowHelperService::class);
//        $workflowHelperService = $container->getDefinition(WorkflowHelperService::class);

//        $workflowHelperService->setArgument('$locator', tagged_locator(tag: 'workflow', indexAttribute: 'name' ))
        $locatableServices = ['workflow' => new Reference('workflow')];


        $builder->autowire(TransitionListener::class);

        $builder->autowire(WorkflowExtension::class)
            ->addArgument(new Reference(WorkflowHelperService::class))
            ->addTag('twig.extension');

        $builder->autowire(SurvosWorkflowDumpCommand::class)
            ->setArgument('$helper', new Reference(WorkflowHelperService::class))
            ->setArgument('$translator', new Reference('translator', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->addTag('console.command')
        ;


        $builder->autowire(WorkflowListener::class)
            ->setArgument('$workflowHelperService', new Reference(WorkflowHelperService::class))
            ->setArgument('$messageBus', new Reference(MessageBusInterface::class))
            ->addTag('kernel.event_listener', ['event' => 'workflow.completed', 'method' => 'onCompleted'])
            ->addTag('kernel.event_listener', ['event' => 'workflow.entered', 'method' => 'onEntered'])
        ;


        }


//        $serivceId = 'survos_command.command_controller';
//        $container->services()->alias(CommandController::class, $serivceId);
//        $builder->autowire(CommandController::class)
//            ->setArgument('$kernel', new Reference('kernel'))
//            ->addTag('container.service_subscriber')
//            ->addTag('controller.service_arguments')
//            ->setPublic(true)
//            ->setAutoconfigured(true)
        ;

//        $workflowControllerId = 'survos_workflow_bundle.workflow_controller';
//        $container->services()->alias(WorkflowController::class, $workflowControllerId  );
        //        $builder->register($workflowControllerId, WorkflowController::class);
//        $builder->autowire(WorkflowController::class)
//            ->setArgument('$helper', new Reference($workflowHelperId))


        $builder->autowire(WorkflowController::class)
            ->addTag('container.service_subscriber')
            ->addTag('controller.service_arguments')
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(true)
        ;

        $builder->autowire(ConfigureFromAttributesService::class)
            ->setAutoconfigured(true)
            ->setPublic(true)
        ;

//        $builder->autowire(Workflow::class)
//            ->setPublic(true);
//
        $builder->autowire(SurvosWorkflowConfigureCommand::class, SurvosWorkflowConfigureCommand::class)
            ->addTag('console.command')
            ->addArgument('%kernel.project_dir%')
        ;

        $builder->setParameter('survos_state.entity_paths', $config['workflow_paths']);

    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('queue_prefix')->defaultValue('%env(STATEQUEUE_PREFIX)%')->end()
                ->scalarNode('base_layout')->defaultValue('base.html.twig')->end()
                ->booleanNode('enable_dynamic_routing')->defaultValue(true)->end()
                ->arrayNode('workflow_paths')
                    ->prototype('scalar')->end()
                    ->defaultValue(['%kernel.project_dir%/src/Workflow'])
                ->end()
                ->scalarNode('async_transport_dsn')->defaultValue('doctrine://default')->end()

                ->end()
            ->end();


    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!$this->isAssetMapperAvailable($builder)) {
            return;
        }


        $dir = realpath(__DIR__.'/../assets/');
        assert(file_exists($dir), $dir);

        $builder->prependExtensionConfig('framework', [
            'asset_mapper' => [
                'paths' => [
                    $dir => '@survos/state',
                ],
            ],
        ]);


        // --- Read OUR bundle raw config (before load()), to get prefix, paths, DSN
        $raw = $builder->getExtensionConfig($this->getAlias()); // 'survos_workflow'
        $queuePrefix       = '%env(STATEQUEUE_PREFIX)%';
        $workflowPaths     = [$builder->getParameter('kernel.project_dir').'/src/Workflow'];
        $asyncTransportDsn = 'doctrine://default';

        foreach (array_reverse($raw) as $cfg) {
            if (isset($cfg['queue_prefix']))        { $queuePrefix = $cfg['queue_prefix']; }
            if (isset($cfg['workflow_paths']))      { $workflowPaths = $cfg['workflow_paths']; }
            if (isset($cfg['async_transport_dsn'])) { $asyncTransportDsn = $cfg['async_transport_dsn']; }
        }

        // Resolve %kernel.project_dir% etc. in paths
        $projectDir = (string)$builder->getParameter('kernel.project_dir');
        $workflowPaths = array_map(
            fn(string $p) => str_replace('%kernel.project_dir%', $projectDir, $p),
            $workflowPaths
        );

        // 1) Build workflows from attributes (add FileResources so changes rebuild the container)
        $built = AttributesWorkflowConfigBuilder::build($workflowPaths);
        foreach ($built['resources'] as $res) {
            $builder->addResource($res);
        }
        if ($built['workflows']) {
            $builder->prependExtensionConfig('framework', [
                'workflows' => ['workflows' => $built['workflows']],
            ]);
        }

        // 2) Collect async transitions from:
        //    a) attribute-built workflows
        $async = array_fill_keys($built['async_transitions'], true);

        //    b) ANY raw framework configs already provided by the app
        foreach ($builder->getExtensionConfig('framework') as $fw) {
            $wf = $fw['workflows']['workflows'] ?? [];
            foreach ($wf as $name => $def) {
                foreach (($def['transitions'] ?? []) as $t) {
                    $tName = $t['name'] ?? null;
                    if ($tName && (($t['metadata']['async'] ?? false) === true)) {
                        $async[$tName] = true;
                    }
                }
            }
        }

        if (!$async) {
            return;
        }

        // Normalize prefix with trailing dot
        $prefix = (string)$queuePrefix;
        if ($prefix !== '' && !str_ends_with($prefix, '.')) {
            $prefix .= '.';
        }

        // 3) Prepend messenger transports for each async transition (Doctrine)
        $transports = [];
        foreach (array_keys($async) as $transition) {
            $queue = $prefix . $transition; // e.g. "sais.translate"
            $table = 'messenger_' . preg_replace('/[^a-z0-9_]+/i', '_', $queue);

            $transports[$queue] = [
                'dsn'     => $asyncTransportDsn,
                'options' => [
                    'table_name' => $table,
                    'queue_name' => $queue,
                    'auto_setup' => true,
                    'use_notify' => true,
                ],
            ];
        }

        if ($transports) {
            $builder->prependExtensionConfig('framework', [
                'messenger' => [
                    'transports' => $transports,
                ],
            ]);
        }
    }



}
