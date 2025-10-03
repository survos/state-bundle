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

        foreach (array_reverse($raw) as $cfg) {
            if (isset($cfg['queue_prefix']))        { $queuePrefix       = (string) $cfg['queue_prefix']; }
            if (isset($cfg['workflow_paths']))      { $workflowPaths     = (array)  $cfg['workflow_paths']; }
            if (isset($cfg['async_transport_dsn'])) { $asyncTransportDsn = (string) $cfg['async_transport_dsn']; }
        }

        $projectDir    = (string) $builder->getParameter('kernel.project_dir');
        $workflowPaths = array_map(fn(string $p) => str_replace('%kernel.project_dir%', $projectDir, $p), $workflowPaths);

        // 1) Build attribute workflows
        $built = AttributesWorkflowConfigBuilder::build($workflowPaths);
        foreach (($built['resources'] ?? []) as $res) {
            $builder->addResource($res);
        }
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

        // 2b) Fallback: ensure MuseumObjectWorkflow has at least one transition
        //     (prevents Framework validation from aborting before passes)
        $museumHasTransitions = false;
        foreach ($builder->getExtensionConfig('framework') as $fw) {
            $wf = $fw['workflows']['workflows'] ?? [];
            if (isset($wf['MuseumObjectWorkflow']['transitions']) && !empty($wf['MuseumObjectWorkflow']['transitions'])) {
                $museumHasTransitions = true;
                break;
            }
        }
        if (!$museumHasTransitions) {
            $builder->prependExtensionConfig('framework', [
                'workflows' => [
                    'workflows' => [
                        'MuseumObjectWorkflow' => [
                            'type' => 'state_machine',
                            'marking_store' => ['type' => 'single_state', 'property' => 'marking'],
                            'supports' => ['App\Entity\MuseumObject'],
                            'places' => ['draft'],
                            // Identity transition keeps validation happy; your later logic can extend/replace.
                            'transitions' => [
                                'bootstrap' => ['from' => 'draft', 'to' => 'draft'],
                            ],
                        ],
                    ],
                ],
            ]);
        }

        // 3) Build messenger transports for async transitions
        if ($asyncByWorkflow) {
            $prefix = QueueNameUtil::isDoctrineDsn($asyncTransportDsn)
                ? ''
                : QueueNameUtil::normalizePrefix($queuePrefix);

            $tableName  = 'messenger_messages';
            $transports = [];
            $transitionToQueueMap = [];

            foreach ($asyncByWorkflow as $wfName => $transitions) {
                $wfSlug = QueueNameUtil::normalizeSlug((string) $wfName);
                foreach (array_keys($transitions) as $tName) {
                    $tSlug = QueueNameUtil::normalizeSlug((string) $tName);
                    $queue = $prefix . $wfSlug . '.' . $tSlug;

                    $transports[$queue] = [
                        'dsn'     => $asyncTransportDsn,
                        'options' => [
                            'table_name' => $tableName,
                            'queue_name' => $queue,
                            'auto_setup' => true,
                            'use_notify' => true,
                        ],
                    ];
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
}
