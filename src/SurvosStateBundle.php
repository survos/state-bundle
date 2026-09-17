<?php
declare(strict_types=1);

namespace Survos\StateBundle;

use Survos\Kit\AbstractUxBundle;
use Survos\Kit\SurvosKitBundle;
use Survos\Kit\Traits\HasConfigurableRoutes;
use Survos\StateBundle\Attribute\Transition;
use Survos\StateBundle\Command\DumpWorkflowPhpCommand;
use Survos\StateBundle\Command\DumpWorkflowsYamlCommand;
use Survos\StateBundle\Command\IterateCommand;
use Survos\StateBundle\Command\MakeWorkflowCommand;
use Survos\StateBundle\Command\StateQueuesDumpCommand;
use Survos\StateBundle\Command\StateStatsCommand;
use Survos\StateBundle\Compiler\RegisterWorkflowEntitiesPass;
use Survos\StateBundle\Compiler\StatePrependExtension;
use Survos\StateBundle\Controller\TransitionDebugController;
use Survos\StateBundle\Controller\WorkflowController;
use Survos\StateBundle\Controller\WorkflowDashboardController;
use Survos\StateBundle\Doctrine\PostLoadSetEnabledTransitionsListener;
use Survos\StateBundle\Doctrine\TransitionListener;
use Survos\StateBundle\Messenger\Middleware\AsyncQueueRoutingMiddleware;
use Survos\StateBundle\Messenger\Middleware\ContextStampingMiddleware;
use Survos\StateBundle\Messenger\Subscriber\ContextFilterSubscriber;
use Survos\StateBundle\Messenger\Subscriber\LogMessageFailureListener;
use Survos\StateBundle\Service\AsyncQueueLocator;
use Survos\StateBundle\Service\ConfigureFromAttributesService;
use Survos\StateBundle\Service\ConsoleEventListener;
use Survos\StateBundle\Service\EntityInterfaceDetector;
use Survos\StateBundle\Service\PrimaryKeyLocator;
use Survos\StateBundle\Service\WorkflowHelperService;
use Survos\StateBundle\Doctrine\InitialPlaceKickoffListener;
use Survos\StateBundle\Service\WorkflowListener;
use Survos\StateBundle\Service\WorkflowStatsService;
use Survos\StateBundle\Traits\EasyMarkingTrait;
use Survos\StateBundle\Traits\MarkingInterface;
use Survos\StateBundle\Twig\Components\WorkflowMarkingComponent;
use Survos\StateBundle\Twig\WorkflowExtension;
use Symfony\Component\Workflow\Command\WorkflowDumpCommand;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_locator;

#[RequiredBundle(SurvosKitBundle::class)]
// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
final class SurvosStateBundle extends AbstractUxBundle
{
    use HasConfigurableRoutes;

    public const ASSET_PACKAGE = 'state';

    public function getAlias(): string
    {
        // matches "survos_state:" in consumer apps
        return 'survos_state';
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $this->addRouteLoaderCompilerPass($container);
        // BatchTransitionMiddleware onto the buses; before MessengerPass (priority 0) builds them.
        $container->addCompilerPass(new \Survos\StateBundle\Compiler\BatchTransitionMiddlewarePass(), \Symfony\Component\DependencyInjection\Compiler\PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);
//        $container->addCompilerPass(new RegisterWorkflowEntitiesPass());
    }

    public function process(ContainerBuilder $container): void
    {

        // Pull the fully-processed Framework configuration
        $frameworkExt = $container->getExtension('framework');
        $rawFramework = $container->getExtensionConfig('framework');
        $configuration = $frameworkExt->getConfiguration($rawFramework, $container);
        $processed = (new Processor())->processConfiguration($configuration, $rawFramework);

        $workflowConfig = $processed['workflows']['workflows'] ?? [];
        $container->setParameter('workflows.configuration', $workflowConfig);


        $container->findDefinition(WorkflowHelperService::class)
            ->setArgument('$managerRegistry', new Reference('doctrine'))
            ->setArgument('$configuration', $workflowConfig)
            ->setArgument('$definitionClasses', $container->getParameter('survos_state.workflow_definition_classes'));

        // Provide the map to the locator if parameter is present (set in prepend)
        if ($container->hasParameter('survos_state.async_transition_map')
            && $container->hasDefinition(AsyncQueueLocator::class)) {
            $def = $container->findDefinition(AsyncQueueLocator::class);
            $def->setArgument('$map', $container->getParameter('survos_state.async_transition_map'));
            $def->setArgument('$placeTransitions', $container->getParameter('survos_state.place_transitions'));
            if ($container->hasParameter('survos_state.batch_transition_map')) {
                $def->setArgument('$batchMap', $container->getParameter('survos_state.batch_transition_map'));
            }
        }
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder);

        $this->captureRouteConfig($config);
        $this->registerRouteLoader($builder);

        // 1) Register middleware + core services
        $builder->autowire(AsyncQueueRoutingMiddleware::class)->setAutoconfigured(true)->setPublic(false);
        $builder->autowire(\Survos\StateBundle\Messenger\Middleware\BatchTransitionMiddleware::class)->setPublic(false)
            ->setArgument('$batchEnabled', $config['batch_enabled'] ?? true);
        $builder->autowire(PrimaryKeyLocator::class)->setAutoconfigured(true)->setPublic(false);

        // 2) AsyncQueueRoutingMiddleware is registered as a service and NOTHING PUTS IT ON A BUS.
        //
        // Read that as: async transition routing does not come from here and never did. It comes
        // from the dispatch sites, which stamp TransportNamesStamp themselves via
        // AsyncQueueLocator::stamps(), onto the per-transition transports StatePrependExtension
        // registers. That is the working, load-bearing path -- WorkflowListener::dispatchTransition,
        // IterateCommand, InitialPlaceKickoffListener, TransitionDebugController and app code all
        // take it. This middleware was only ever the belt to that pair of braces: a net under
        // dispatch sites that forget to stamp, whose messages are handled inline instead.
        //
        // There used to be a prependExtensionConfig('framework', ...) here adding it to
        // messenger.bus.default. It was inert for two independent reasons, both confirmed in
        // mediary on 2026-09-11:
        //
        //  1. Prepending framework config from loadExtension() is too late. FrameworkBundle's
        //     extension has already run, so the config is merged into a value nobody reads again.
        //     It still showed up in `debug:config framework messenger.buses`, which is what made
        //     this look wired for as long as it did -- then RemoveUnusedDefinitionsPass deleted the
        //     service as "unused" (see var/cache/dev/*ContainerCompiler.log).
        //  2. framework.messenger.buses.*.middleware does not deep-merge. Any other bundle's
        //     prepend REPLACES the whole list; Inspector APM's InspectorExtension::prepend won in
        //     mediary. So even correctly timed, a prepend is not a safe way to add middleware.
        //
        // The prepend is gone rather than fixed, deliberately. Turning this on is a real behavior
        // change -- unstamped dispatches of async transitions stop running inline and start
        // queueing -- and it is not needed for correctness anywhere: as of 2026-09-12 the only two
        // sites that had forgotten to stamp (ssai ImageTaskController, harvest
        // DatasetMediaGateListener) now stamp at the dispatch site like everything else.
        //
        // To activate it later, do NOT reinstate the prepend. Copy BatchTransitionMiddlewarePass:
        // splice the class into each bus's `<bus>.middleware` parameter before MessengerPass, and
        // gate it on enable_dynamic_routing by registering the definition only when that is true
        // (the pass then early-returns on !hasDefinition, exactly as the batch pass does).



        // Batched transitions (#[Transition(batch: N)]) — the one handler for BatchedTransitionMessage.
        $builder->autowire(\Survos\StateBundle\Messenger\BatchTransitionHandler::class)
            ->setAutoconfigured(true)
            ->setArgument('$defaultBatchSize', $config['batch_size'] ?? 100)
            ->setArgument('$idleTimeout', $config['batch_idle_timeout'] ?? 5);

        foreach ([AsyncQueueLocator::class,
                     WorkflowStatsService::class,
                     EntityInterfaceDetector::class,
                     WorkflowHelperService::class] as $class) {
            $builder->autowire($class)
                ->setPublic(true)
                ->setAutowired(true)
                ->setAutoconfigured(true);
        }

            $builder->autowire(WorkflowController::class)
                ->setPublic(true)
                ->setArgument('$workflowHelperService', new Reference(WorkflowHelperService::class))
                ->setArgument('$serializer', new Reference('serializer'))
//                ->setAutoconfigured(true)
                ->setAutowired(true)
                ->addTag('container.service_subscriber')
                ->addTag('controller.service_arguments');

        $builder->autowire(WorkflowDashboardController::class)
            ->setPublic(true)
            ->setAutoconfigured(true)
            ->setAutowired(true)
            ->addTag('container.service_subscriber')
            ->addTag('controller.service_arguments');

        $builder->autowire(TransitionDebugController::class)
            ->setPublic(true)
            ->setAutowired(true)
            ->addTag('container.service_subscriber')
            ->addTag('controller.service_arguments');

        foreach ([IterateCommand::class,
                     DumpWorkflowPhpCommand::class,
                     DumpWorkflowsYamlCommand::class,
                     MakeWorkflowCommand::class,
                     StateQueuesDumpCommand::class,
                 ] as $commandClass) {
            if (class_exists($commandClass)) {
                $builder->autowire($commandClass)->setAutoconfigured(true)->addTag('console.command');
            }
        }


        $services = $container->services();
        // NOT best practice for bundles.
        $services
            ->defaults()
            ->autowire()
            ->autoconfigure();


        // middleware
        $services->set(ContextStampingMiddleware::class)
            ->tag('messenger.middleware');

        // explicit failure listener (you already had this)
        $services->set(LogMessageFailureListener::class)
            ->tag('kernel.event_listener', [
                'event'    => WorkerMessageFailedEvent::class,
                'method'   => '__invoke',
                'priority' => 0,
            ]);

        // explicit receive-time filter listener
        $services->set(ContextFilterSubscriber::class)
//            ->arg('$filterEnvName', '%env(default:CONTEXT_STAMP:STATE_FILTER_ENV)%');
            ->arg('$filterEnvName', 'CONTEXT_STAMP') // default; STATE_FILTER_ENV can override at runtime
            ->tag('kernel.event_listener', [
                'event'    => WorkerMessageReceivedEvent::class,
                'method'   => '__invoke',
                'priority' => 1000,
            ]);


        $builder->setParameter('survos_workflow.base_layout', $config['base_layout'] ?? 'base.html.twig');

        // Optional: expose Symfony Workflow dump/viz via tagged locator (if the commands are present)
        if (class_exists(WorkflowDumpCommand::class)) {
            $container->services()
                ->set('console.command.survos_workflow_dump', WorkflowDumpCommand::class)
                ->args([tagged_locator('workflow', 'name')]);
        }
        $builder->autowire(StateStatsCommand::class)
            ->setPublic(true)
            ->setAutowired(true)
//            ->setArgument('$filterEnvName', '%env(default:CONTEXT_STAMP:STATE_FILTER_ENV)%')
            ->addTag('console.command')
            ->setAutoconfigured(true);


        $builder->autowire(WorkflowExtension::class)
            ->addArgument(new \Symfony\Component\DependencyInjection\Reference(WorkflowHelperService::class))
            ->addTag('twig.extension');

        $builder->autowire(WorkflowMarkingComponent::class)
            ->setAutoconfigured(true);

        // Auto-register a "Workflows" dropdown in the Tabler admin navbar (like every
        // other Survos bundle). Only when tabler-bundle is present; autoconfigure picks
        // up the #[AsEventListener] on the subscriber.
        if (class_exists(\Survos\TablerBundle\Event\MenuEvent::class)) {
            $builder->autowire(\Survos\StateBundle\Menu\StateMenuSubscriber::class)
                ->setAutowired(true)
                ->setAutoconfigured(true);
        }

//        $builder->autowire(ConfigureFromAttributesService::class)->setAutoconfigured(true)->setPublic(true);
//        $builder->autowire(TransitionListener::class)->setAutoconfigured(true)->setPublic(true);
//        $builder->autowire(PostLoadSetEnabledTransitionsListener::class)->setAutoconfigured(true)->setPublic(true);

        // Starts the workflow for a newly persisted entity, from the initial
        // place's own `next` — the thing #[Place(initial: true, next: [...])]
        // has always described and nothing ever implemented. Without it every
        // app hand-rolls the kickoff and re-decides what the flow already
        // declares. Inert unless the initial place actually declares `next`.
        $builder->autowire(InitialPlaceKickoffListener::class)
            ->setArgument('$workflowHelperService', new Reference(WorkflowHelperService::class))
            ->setArgument('$messageBus', new Reference(MessageBusInterface::class))
            ->addTag('doctrine.event_listener', ['event' => 'postPersist'])
            ->addTag('doctrine.event_listener', ['event' => 'postFlush']);

        // Workflow listener wiring
        $builder->autowire(WorkflowListener::class)
            ->setArgument('$workflowHelperService', new \Symfony\Component\DependencyInjection\Reference(WorkflowHelperService::class))
            ->setArgument('$messageBus', new \Symfony\Component\DependencyInjection\Reference(MessageBusInterface::class))
            ->addTag('kernel.event_listener', ['event' => 'workflow.completed', 'method' => 'onCompleted'])
            ->addTag('kernel.event_listener', ['event' => 'workflow.entered', 'method' => 'onEntered']);

        $builder->autowire(ConsoleEventListener::class)
            ->setAutoconfigured(true)
            ->setAutoconfigured(true)
            ->setPublic(true)
            ->setArgument('$workflowHelperService', new Reference(WorkflowHelperService::class))
            ->setArgument('$messageBus', new Reference(MessageBusInterface::class))
//            ->addTag('console.event_listener', ['event' => 'workflow.completed', 'method' => 'onCompleted'])
//            ->addTag('kernel.event_listener', ['event' => 'workflow.entered', 'method' => 'onEntered'])
        ;


        $builder->setParameter('survos_state.entity_paths', $config['workflow_paths'] ?? ['%kernel.project_dir%/src/Workflow']);
        $builder->setParameter('survos_state.allow_force_place', $config['allow_force_place'] ?? '%kernel.debug%');
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $children = $definition->rootNode()->children();
        $this->addRouteOptions($children, '/state');
        $children
            // Prefix is only used for non-Doctrine brokers. Empty by default.
            ->scalarNode('queue_prefix')->defaultValue('')->end()
            ->scalarNode('base_layout')->defaultValue('base.html.twig')->end()
            // Currently INERT, and kept only so apps that set it still boot (mediary does).
            // Nothing reads it: AsyncQueueRoutingMiddleware is registered but on no bus, and
            // routing comes from AsyncQueueLocator::stamps() at the dispatch sites instead.
            // See the long note in loadExtension() for what this would gate if activated.
            ->booleanNode('enable_dynamic_routing')->defaultValue(true)
                ->info('Inert as of 2026-09-12: transition routing comes from AsyncQueueLocator::stamps() at the dispatch site, not from middleware. See SurvosStateBundle::loadExtension().')
            ->end()
            ->integerNode('batch_size')->defaultValue(100)->info('Default size for #[Transition(batch: true-ish)] groups; a transition\'s own batch: N wins')->end()
            ->integerNode('batch_idle_timeout')->defaultValue(5)->info('Seconds of worker idleness after which a partial batch is flushed')->end()
            ->booleanNode('batch_enabled')->defaultTrue()->info('Off: #[Transition(batch: N)] transitions travel as plain TransitionMessages, one at a time, exactly as if unbatched. Env-able: \'%env(bool:APP_BATCH)%\'')->end()
            // Force-place: set a marking directly, running no transition and no guard.
            //
            // Debug-only by default, and that default is the recommendation. It is the
            // one control here that can put an entity somewhere the workflow says it
            // cannot be -- which is exactly why it is useful (re-run triage on an
            // already-triaged image without a manual UPDATE) and exactly why it should
            // not be a button a tired operator can reach on a Friday afternoon. If you
            // do enable it in production, gate the surrounding template on a role too;
            // this flag is a kill switch, not an authorization system.
            ->scalarNode('allow_force_place')->defaultValue('%kernel.debug%')->end()
            ->arrayNode('workflow_paths')->prototype('scalar')->end()
            ->defaultValue(['%kernel.project_dir%/src/Workflow'])->end()
            ->scalarNode('async_transport_dsn')->defaultValue('doctrine://default')->end()
            // Explicit switch between the two dynamic-queue strategies (see
            // StatePrependExtension) — not inferred from async_transport_dsn's scheme,
            // since that's frequently an unresolved %env(...)% placeholder at compile time.
            ->enumNode('queue_driver')->values(['doctrine', 'rabbitmq'])->defaultValue('doctrine')->end()
            // Broker-only, applied by StatePrependExtension to every dynamic rabbitmq queue and
            // ignored by the doctrine driver, which rejects these as unknown options. An app used
            // to declare them in its own messenger.yaml, which meant the AMQP shape leaked into
            // when@test and broke any test that instantiated a transport.
            //
            // max_priority declares the queues as priority queues (x-max-priority), so a publisher
            // can stamp a message's priority and the broker delivers waiting messages in that
            // order. Null leaves the queues as plain classic queues. Switching it on for queues
            // that already exist requires draining and deleting them first: RabbitMQ answers 406
            // PRECONDITION_FAILED rather than redefining a live queue.
            ->integerNode('max_priority')->defaultNull()->min(1)->max(255)->end()
            ->integerNode('prefetch_count')->defaultNull()->min(1)->end()
            // Per-queue overrides keyed by queue name ("<workflow>.<transition>", as the queue is
            // built), for the one stage that wants a different prefetch from the rest -- a batched
            // AI handler wants several deliveries in flight, an archive worker wants one.
            ->arrayNode('queue_options')
                ->useAttributeAsKey('queue')
                ->arrayPrototype()
                    ->children()
                        ->integerNode('max_priority')->min(1)->max(255)->end()
                        ->integerNode('prefetch_count')->min(1)->end()
                    ->end()
                ->end()
            ->end()
            // Retry policy applied to every dynamic per-transition transport this bundle
            // registers (both drivers). Symfony's own defaults; override per app.
            //
            // These were pinned to max_retries: 0 for the whole rabbitmq era, for two
            // reasons that are worth keeping apart now that they have come unstuck:
            //
            // 1. A POLICY argument, still valid: a failed transition is often not transient.
            //    The failures we actually see are "the input isn't there" — a missing zip, a
            //    missing raw core — and retrying cannot conjure the file; going straight to
            //    the failure transport is faster and more honest. If that describes your
            //    workflows, set max_retries: 0 here and keep it.
            //
            // 2. A BUG, now fixed: jwage/phpamqplib-messenger's delay queue crashed RabbitMQ
            //    4.3 consumers outright, so 0 was the only safe value regardless of policy.
            //    Fixed upstream in #124 and opted into by StatePrependExtension.
            //
            // The default is 3 because reason 2 was doing most of the work, and a consumer
            // dying mid-handle — which max_retries: 0 turns into a silently lost message —
            // is more common than an unretryable input. Note this value is ignored on a
            // rabbitmq driver whose installed jwage version predates #124: there the delay
            // queue is still unsafe, so 0 is forced. See $delayDurableSupported.
            ->arrayNode('retry_strategy')
                ->addDefaultsIfNotSet()
                ->children()
                    ->integerNode('max_retries')->min(0)->defaultValue(3)->end()
                    ->integerNode('delay')->min(0)->defaultValue(1000)->end()
                    ->floatNode('multiplier')->min(1)->defaultValue(2)->end()
                    ->integerNode('max_delay')->min(0)->defaultValue(0)->end()
                ->end()
            ->end()
        ->end();
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::prependExtension($container, $builder);

        // Delegate to compile-time builder
        StatePrependExtension::prepend($container, $builder, $this->getAlias());

        // Icons used by the Workflows admin-navbar dropdown (StateMenuSubscriber).
        // They're referenced from PHP, which the ux:icons template scanner can't see,
        // so declare them as aliases (apps may override in their own ux_icons.yaml).
        if ($builder->hasExtension('ux_icons')) {
            $builder->prependExtensionConfig('ux_icons', [
                'aliases' => [
                    'sitemap'   => 'tabler:sitemap',
                    'chart-pie' => 'tabler:chart-pie',
                ],
            ]);
        }
    }
}
