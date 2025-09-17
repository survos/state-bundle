<?php
declare(strict_types=1);

namespace Survos\StateBundle\Compiler;

use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class BuildAsyncQueuesFromWorkflowsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $frameworkExt = $container->getExtension('framework');
        $rawConfigs   = $container->getExtensionConfig('framework');
        $configuration = $frameworkExt->getConfiguration($rawConfigs, $container);
        $processed     = (new Processor())->processConfiguration($configuration, $rawConfigs);

        $wf = $processed['workflows']['workflows'] ?? [];
        $prefix = (string)($container->getParameter('survos_state.queue_prefix') ?? '');
        if ($prefix !== '' && !str_ends_with($prefix, '.')) {
            $prefix .= '.';
        }

        $map = [];
        foreach ($wf as $wfName => $def) {
            foreach (($def['transitions'] ?? []) as $t) {
                $name = $t['name'] ?? null;
                if (!$name) { continue; }
                if ((($t['metadata']['async'] ?? false) === true)) {
                    $map[$name] = $prefix . $name;
                }
            }
        }

        $container->setParameter('survos_state.async_transition_queues', $map);
    }
}
