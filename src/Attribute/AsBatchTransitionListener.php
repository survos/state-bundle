<?php

declare(strict_types=1);

namespace Survos\StateBundle\Attribute;

use Survos\StateBundle\Event\BatchTransitionEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Receive all subjects of a batch of a #[Transition(batch: N)] transition at once
 * ({@see BatchTransitionEvent}). The batched counterpart of Symfony's #[AsTransitionListener],
 * built the same way: an event listener with a computed event name.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class AsBatchTransitionListener extends AsEventListener
{
    public function __construct(
        string $workflow,
        string $transition,
        ?string $method = null,
        int $priority = 0,
        ?string $dispatcher = null,
    ) {
        parent::__construct(BatchTransitionEvent::name($workflow, $transition), $method, $priority, $dispatcher);
    }
}
