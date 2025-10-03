<?php
declare(strict_types=1);

namespace Survos\StateBundle\Messenger\Subscriber;

use Survos\StateBundle\Messenger\Stamp\ContextStamp;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

/**
 * Skips handling unless the message's ContextStamp value matches an allow-list
 * read from an environment variable (name itself is configurable).
 */
#[AsEventListener(event: WorkerMessageReceivedEvent::class, priority: 1000)]
final class ContextFilterSubscriber
{
    public function __construct(
        private readonly string $filterEnvName = 'CONTEXT_STAMP'
    ) {}

    public function __invoke(WorkerMessageReceivedEvent $event): void
    {
        $envName = $this->filterEnvName ?: 'CONTEXT_STAMP';

        // allow overriding the *name* via STATE_FILTER_ENV
        $envName = \getenv('STATE_FILTER_ENV') ?: $envName;

        $raw = \getenv($envName);
        if ($raw === false || $raw === '') {
            return; // filter off
        }

        $allowed = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn($v) => $v !== ''));
        if ($allowed === []) {
            return;
        }

        /** @var ContextStamp|null $stamp */
        $stamp = $event->getEnvelope()->last(ContextStamp::class);
        if (!$stamp || !in_array((string)$stamp->value, array_map('strval', $allowed), true)) {
            $event->shouldHandle(false);
        }
//        if ($event->shouldHandle()) {
//            dd($stamp, $event->getEnvelope());
//        }
//        dump($stamp, $event->shouldHandle());
    }
}
