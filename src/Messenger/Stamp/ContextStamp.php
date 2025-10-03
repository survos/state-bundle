<?php
declare(strict_types=1);

namespace Survos\StateBundle\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Generic, persisted stamp: a named key (default 'context') with a string|int value.
 */
final class ContextStamp implements StampInterface
{
    public function __construct(
        public readonly string|int $value,
        public readonly string $key = 'context',
    ) {}
}
