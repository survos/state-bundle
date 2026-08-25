<?php
declare(strict_types=1);

namespace Survos\StateBundle\Compiler;

use Survos\StateBundle\Config\AttributesWorkflowConfigBuilder;
use Survos\StateBundle\Util\QueueNameUtil;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Compile-time "prepend" logic for state-bundle:
 *  - build attribute workflows
 *  - collect async transitions per workflow (metadata.async=true)
 *  - declare Messenger transports (single Doctrine table; per-queue queue_name)
 *  - publish workflow→transition→queue map parameter
 */
// Survos/StateBundle/Compiler/StatePrependExtension.php
final class StatePrependExtension
{
    public static function prepend(ContainerConfigurator $container, ContainerBuilder $builder, string $alias = 'survos_state'): void
    {
        $raw = $builder->getExtensionConfig($alias);
        $queuePrefix       = '';
        $workflowPaths     = [$builder->getParameter('kernel.project_dir') . '/src/Workflow'];
        $asyncTransportDsn = 'doctrine://default';
        // Explicit switch, not DSN sniffing: async_transport_dsn is frequently an
        // unresolved "%env(...)%" placeholder at this point in compilation, so its
        // scheme can't be relied on to pick the transport-options shape.
        $queueDriver       = 'doctrine';
        // Symfony's own retry defaults, restated here because this pass reads RAW
        // extension config (getExtensionConfig) and so never sees the values
        // Configuration would have applied. Keep the two in sync with
        // SurvosStateBundle::configure().
        $retryStrategy     = [
            'max_retries' => 3,
            'delay'       => 1000,
            'multiplier'  => 2,
            'max_delay'   => 0,
        ];

        // In load order, so LATER config wins — the same precedence the
        // Configuration processor applies. ContainerBuilder::getExtensionConfig()
        // returns configs oldest-first (loadFromExtension appends;
        // prependExtensionConfig unshifts, which is why prepended defaults sit at
        // the front and rank lowest). Iterating array_reverse() therefore let the
        // EARLIEST file win, so config/packages/{env}/ overrides were silently
        // ignored: packages sets queue_driver: rabbitmq in the base file and
        // doctrine under when@dev, debug:config correctly reported doctrine, and
        // this pass still built RabbitMQ transports. Verified by restoring the
        // array_reverse() and watching the parameter flip back.
        foreach ($raw as $cfg) {
            if (isset($cfg['queue_prefix']))        { $queuePrefix       = (string) $cfg['queue_prefix']; }
            if (isset($cfg['workflow_paths']))      { $workflowPaths     = (array)  $cfg['workflow_paths']; }
            if (isset($cfg['async_transport_dsn'])) { $asyncTransportDsn = (string) $cfg['async_transport_dsn']; }
            if (isset($cfg['queue_driver']))         { $queueDriver       = (string) $cfg['queue_driver']; }
            // Merged, not replaced, so an app can override just max_retries.
            if (isset($cfg['retry_strategy']))      { $retryStrategy     = array_merge($retryStrategy, (array) $cfg['retry_strategy']); }
        }

        // Published so other bundles (e.g. tabler-bundle's RabbitMqMenuSubscriber) can
        // soft-detect "state-bundle is installed and configured for rabbitmq" without a
        // hard dependency on this bundle's classes.
        $builder->setParameter('survos_state.queue_driver', $queueDriver);
        $builder->setParameter('survos_state.async_transport_dsn', $asyncTransportDsn);

        $projectDir    = (string) $builder->getParameter('kernel.project_dir');
        $workflowPaths = array_map(fn(string $p) => str_replace('%kernel.project_dir%', $projectDir, $p), $workflowPaths);

        // Auto-include workflow dirs from known optional bundles
        foreach ([
            'Survos\AiWorkflowBundle\SurvosAiWorkflowBundle',
        ] as $bundleClass) {
            if (class_exists($bundleClass)) {
                $bundleDir = dirname((new \ReflectionClass($bundleClass))->getFileName());
                $extraPath = $bundleDir . '/Workflow';
                if (is_dir($extraPath) && !in_array($extraPath, $workflowPaths, true)) {
                    $workflowPaths[] = $extraPath;
                }
            }
        }

        // 1) Build attribute workflows
        $built = AttributesWorkflowConfigBuilder::build($workflowPaths);
        foreach (($built['resources'] ?? []) as $res) {
            $builder->addResource($res);
        }
        $builder->setParameter('survos_state.workflow_definition_classes', $built['definition_classes'] ?? [
            'by_workflow' => [],
            'by_support' => [],
        ]);
        if (!empty($built['workflows'])) {
            $builder->prependExtensionConfig('framework', [
                'workflows' => ['workflows' => $built['workflows']],
            ]);
        }

        // 2) Collect async transitions (+ seed initialTransitions safely)
        $asyncByWorkflow    = [];
        $initialTransitions = []; // ✅ CRITICAL: initialize!

        if (!empty($built['async_by_workflow']) && is_array($built['async_by_workflow'])) {
            foreach ($built['async_by_workflow'] as $wfName => $transitions) {
                foreach ((array) $transitions as $tName) {
                    $asyncByWorkflow[$wfName][$tName] = true;
                }
            }
        } elseif (!empty($built['async_transitions']) && !empty($built['name'])) {
            foreach ((array) $built['async_transitions'] as $tName) {
                $asyncByWorkflow[$built['name']][$tName] = true;
            }
        }

        // also read framework-defined workflows (guard all keys)
        foreach ($builder->getExtensionConfig('framework') as $fw) {
            $wf = $fw['workflows']['workflows'] ?? [];
            foreach ($wf as $wfName => $def) {
                // places may be associative with metadata; guard it
                foreach (($def['places'] ?? []) as $placeName => $placeData) {
                    if (is_array($placeData) && isset($placeData['metadata']['next'])) {
                        $initialTransitions[(string) $placeName] = $placeData['metadata']['next'];
                    }
                }
                foreach ((array) ($def['transitions'] ?? []) as $t) {
                    $tName = $t['name'] ?? null;
                    if (!$tName) { continue; }
                    if (($t['metadata']['async'] ?? false) === true) {
                        $asyncByWorkflow[$wfName][$tName] = true;
                    }
                    if (isset($t['metadata']['transport']) && is_string($t['metadata']['transport'])) {
                        $asyncByWorkflow[$wfName][$tName] = $t['metadata']['transport'];
                    }
                }
            }
        }

        // ✅ Always set known parameters to defined (possibly empty) arrays
        $builder->setParameter('survos_state.place_transitions', $initialTransitions);
        $builder->setParameter('survos_state.async_transition_map', []);

        if ($asyncByWorkflow) {
            // Doctrine shares one physical table (filtered by queue_name); rabbitmq
            // shares one physical vhost (one exchange+queue per transition, named
            // after the queue). Either way the prefix only matters when queue names
            // could collide across apps sharing the same broker/table.
            $prefix = $queueDriver === 'doctrine'
                ? ''
                : QueueNameUtil::normalizePrefix($queuePrefix);

            $tableName  = 'messenger_messages';
            $transports = [];
            $transitionToQueueMap = [];

            // Can the installed jwage/phpamqplib-messenger declare a DURABLE delay queue?
            //
            // Its Connection::setupDelayQueue() used to call queue_declare() without passing
            // durable:, and php-amqplib defaults $durable=false/$exclusive=false — a transient
            // non-exclusive queue. RabbitMQ 4.0 deprecated those and 4.3 denies them by
            // default, so the broker answers INTERNAL_ERROR and CLOSES THE CONNECTION. That
            // kills messenger:consume, the restart policy brings it back, and the next delayed
            // publish kills it again: a crash loop where the queue never drains and nothing in
            // the logs points at the delay queue (harvest: 7,035 restarts, 2,229 messages
            // frozen). The main queues are unaffected — QueueConfig defaults durable ?? true;
            // only the delay path is.
            //
            // jwage/phpamqplib-messenger#124 (merged 2026-08-25, main, not yet tagged) adds an
            // opt-in `delay.durable` option, deliberately defaulting to false so existing
            // transient delay queues don't start failing queue_declare with PRECONDITION_FAILED.
            // We opt in.
            //
            // Probed rather than assumed because the option is REJECTED outright by older
            // versions (DelayConfig::validate() against AVAILABLE_OPTIONS), which would break
            // every transport here rather than just the delay path. On a version without it
            // there is no safe delayed publish at all, so we fall back to the pre-#124 shape:
            // max_retries 0, which is what keeps the delay queue from ever being declared.
            // Anything dispatched with an explicit DelayStamp still hits the bug on such a
            // version — retries were never the only path to setupDelayQueue().
            $delayDurableSupported = property_exists(
                \Jwage\PhpAmqpLibMessengerBundle\Transport\Config\DelayConfig::class,
                'durable',
            );

            foreach ($asyncByWorkflow as $wfName => $transitions) {
                $wfSlug = QueueNameUtil::normalizeSlug((string) $wfName);
                foreach (array_keys($transitions) as $tName) {
                    $tSlug = QueueNameUtil::normalizeSlug((string) $tName);
                    $queue = $prefix . $wfSlug . '.' . $tSlug;

                    $transports[$queue] = match ($queueDriver) {
                        // jwage/phpamqplib-messenger's minimal DSN form
                        // (phpamqplib://user:pass@host:port/vhost/exchange) auto-creates
                        // an exchange + a same-named bound queue — the RabbitMQ analogue
                        // of Doctrine's "table + queue_name column" trick: one dynamic
                        // transport per async transition, no manual broker setup.
                        //
                        // `delay.durable` is what makes retries — and any DelayStamp —
                        // survivable on RabbitMQ 4.3+; see $delayDurableSupported above for
                        // the whole story and for what happens when it isn't available.
                        'rabbitmq' => [
                            'dsn'     => self::appendPathSegment($asyncTransportDsn, $queue),
                            'options' => $delayDurableSupported
                                ? ['auto_setup' => true, 'delay' => ['durable' => true]]
                                : ['auto_setup' => true],
                            'retry_strategy' => $delayDurableSupported
                                ? $retryStrategy
                                : ['max_retries' => 0],
                        ],
                        default => [
                            'dsn'     => $asyncTransportDsn,
                            'options' => [
                                'table_name' => $tableName,
                                'queue_name' => $queue,
                                'auto_setup' => true, // creates the table in doctrine if it doesn't already exist
//                                'use_notify' => true, // automatic with postgres
                                'get_notify_timeout' => 30000,
                            ],
                            'retry_strategy' => $retryStrategy,
                        ],
                    };
                    $transitionToQueueMap[$wfSlug][$tSlug] = $queue;
                }
            }

            if ($transports) {
                $builder->prependExtensionConfig('framework', [
                    'messenger' => [
                        'transports' => $transports,
                    ],
                ]);
            }

            $builder->setParameter('survos_state.async_transition_map', $transitionToQueueMap);
        }
    }

    /** Append a path segment to a DSN, preserving any query string. */
    private static function appendPathSegment(string $dsn, string $segment): string
    {
        [$base, $query] = str_contains($dsn, '?') ? explode('?', $dsn, 2) : [$dsn, null];
        $base = rtrim($base, '/') . '/' . $segment;
        return $query !== null ? $base . '?' . $query : $base;
    }
}
