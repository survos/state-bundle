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
final class StatePrependExtension
{
    public static function prepend(ContainerConfigurator $container, ContainerBuilder $builder, string $alias = 'survos_state'): void
    {
        // --- Read OUR bundle raw config (before load()), to get prefix/paths/DSN
        $raw = $builder->getExtensionConfig($alias);
        $queuePrefix       = '';
        $workflowPaths     = [$builder->getParameter('kernel.project_dir') . '/src/Workflow'];
        $asyncTransportDsn = 'doctrine://default';

        foreach (array_reverse($raw) as $cfg) {
            if (isset($cfg['queue_prefix']))        { $queuePrefix       = (string) ($cfg['queue_prefix']); }
            if (isset($cfg['workflow_paths']))      { $workflowPaths     = (array)  ($cfg['workflow_paths']); }
            if (isset($cfg['async_transport_dsn'])) { $asyncTransportDsn = (string) ($cfg['async_transport_dsn']); }
        }

        // Resolve %kernel.project_dir% in workflow paths
        $projectDir    = (string) $builder->getParameter('kernel.project_dir');
        $workflowPaths = array_map(fn(string $p) => str_replace('%kernel.project_dir%', $projectDir, $p), $workflowPaths);

        // 1) Build workflows from attributes (and add resources for cache invalidation)
        $built = AttributesWorkflowConfigBuilder::build($workflowPaths);
        foreach ($built['resources'] as $res) {
            $builder->addResource($res);
        }
        if (!empty($built['workflows'])) {
            $builder->prependExtensionConfig('framework', [
                'workflows' => ['workflows' => $built['workflows']],
            ]);
        }

        // 2) Collect async transitions grouped by workflow
        $asyncByWorkflow = [];

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

        // also pick up framework-defined workflows with metadata.async=true
        foreach ($builder->getExtensionConfig('framework') as $fw) {
            $wf = $fw['workflows']['workflows'] ?? [];
            foreach ($wf as $wfName => $def) {
                // this doesn't feel like the right place!
                foreach ($def['places'] as $placeName => $placeData) {
                    if ($next = $placeData['metadata']['next']??false) {
                        $initialTransitions[$placeName] = $next;
                    }
                }
                foreach (($def['transitions'] ?? []) as $t) {
                    $tName = $t['name'] ?? null;
                    if ($tName && (($t['metadata']['async'] ?? false) === true)) {
                        $asyncByWorkflow[$wfName][$tName] = true;
                    }
                    // override the transport name?
                    if ($tName && (($transport = $t['metadata']['transport'] ?? false) === true)) {
                        $asyncByWorkflow[$wfName][$tName] = $transport;
                    }

                }
            }
        }

        $builder->setParameter('survos_state.place_transitions', $initialTransitions);
        $builder->setParameter('survos_state.async_transition_map', []);

        if (!$asyncByWorkflow) {
            return;
        }

        // 3) Prefix only for non-Doctrine DSNs
        $prefix = QueueNameUtil::isDoctrineDsn($asyncTransportDsn)
            ? ''
            : QueueNameUtil::normalizePrefix($queuePrefix);

        // 4) Build transports: single table, distinct queue_name per workflow+transition
        $tableName  = 'messenger_messages';
//        $tableName  = 'messenger_workflow_messages';
        $transports = [];
        /** @var array<string, array<string, string>> $transitionToQueueMap */
        $transitionToQueueMap = [];

        foreach ($asyncByWorkflow as $wfName => $transitions) {
            $wfSlug = QueueNameUtil::normalizeSlug((string) $wfName);
            foreach (array_keys($transitions) as $tName) {
                $tSlug = QueueNameUtil::normalizeSlug((string) $tName);
                $queue = $prefix . $wfSlug . '.' . $tSlug;

                $transports[$queue] = [
                    'dsn'     => $asyncTransportDsn,
                    'options' => [
                        'table_name' => $tableName, // constant for Doctrine
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
//        dd($transitionToQueueMap, $initialTransitions);

        // Publish the workflow→transition→queue map for AsyncQueueLocator
        $builder->setParameter('survos_state.async_transition_map', $transitionToQueueMap);
    }
}
