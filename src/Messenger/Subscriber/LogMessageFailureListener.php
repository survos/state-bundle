<?php
declare(strict_types=1);

namespace Survos\StateBundle\Messenger\Subscriber;

use Psr\Log\LoggerInterface;
use Survos\StateBundle\Messenger\Stamp\ContextStamp;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

#[AsEventListener(event: WorkerMessageFailedEvent::class, priority: 0)]
final class LogMessageFailureListener
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $envelope = $event->getEnvelope();
        $e        = $event->getThrowable();

        $msg      = $envelope->getMessage();
        $msgClass = is_object($msg) ? get_class($msg) : get_debug_type($msg);

        $transport = $envelope->last(TransportNamesStamp::class)?->getTransportNames()[0] ?? 'unknown';
        $context   = $envelope->last(ContextStamp::class);
        $contextStr = $context ? sprintf('%s=%s', $context->key, $context->value) : 'n/a';

        // concise headline
        $this->logger->error(sprintf(
            'Messenger failure in %s on transport "%s" (context: %s): %s: %s',
            $msgClass, $transport, $contextStr, get_class($e), $e->getMessage()
        ));

        // full stack (ensure monolog include_stacktraces: true in your app to see frames)
        $this->logger->error('Stack trace', ['exception' => $e]);
    }
}
