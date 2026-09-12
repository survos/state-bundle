<?php

declare(strict_types=1);

namespace Survos\StateBundle\Compiler;

use Survos\StateBundle\Messenger\Middleware\BatchTransitionMiddleware;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Puts BatchTransitionMiddleware on every message bus, just before send_message.
 *
 * Why a compiler pass and not a prepended `framework.messenger.buses.*.middleware`: that node does
 * not deep-merge, so every bundle that prepends it REPLACES the others' lists and one wins.
 * In mediary, Inspector APM's prepend silently dropped state-bundle's (found 2026-09-11; the same
 * fate as AsyncQueueRoutingMiddleware, see SurvosStateBundle::loadExtension()). Framework leaves
 * each bus's final middleware list in the `<bus>.middleware` parameter for MessengerPass, which
 * turns it into the bus; this pass runs before MessengerPass (priority 10) and edits that list.
 */
final class BatchTransitionMiddlewarePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(BatchTransitionMiddleware::class)) {
            return;
        }
        foreach (array_keys($container->findTaggedServiceIds('messenger.bus')) as $busId) {
            $param = $busId . '.middleware';
            if (!$container->hasParameter($param)) {
                continue;
            }
            $middleware = $container->getParameter($param);
            if (in_array(BatchTransitionMiddleware::class, array_column($middleware, 'id'), true)) {
                continue;
            }
            $at = array_search('send_message', array_column($middleware, 'id'), true);
            array_splice($middleware, $at === false ? \count($middleware) : $at, 0, [['id' => BatchTransitionMiddleware::class, 'arguments' => []]]);
            $container->setParameter($param, $middleware);
        }
    }
}
