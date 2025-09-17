<?php
declare(strict_types=1);

namespace Survos\StateBundle\Messenger\Middleware;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Survos\StateBundle\Message\TransitionMessage;
use Survos\StateBundle\Service\AsyncQueueLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Doctrine-first routing:
 * If a TransitionMessage is async (per AsyncQueueLocator) and no transport is set,
 * stamp the Doctrine transport name (queue) via TransportNamesStamp.
 */
final class AsyncQueueRoutingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AsyncQueueLocator $locator,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        // Only during SEND path
        if ($envelope->last(ReceivedStamp::class) || $envelope->last(HandledStamp::class)) {
            return $stack->next()->handle($envelope, $stack);
        }

        $msg = $envelope->getMessage();
        if (!$msg instanceof TransitionMessage) {
            return $stack->next()->handle($envelope, $stack);
        }

        // Respect explicit sender choice
        if ($envelope->last(TransportNamesStamp::class)) {
            return $stack->next()->handle($envelope, $stack);
        }

        $transition = $msg->getTransitionName();
        if (!$this->locator->isAsync($transition)) {
            // not async → sync fallback
            return $stack->next()->handle($envelope, $stack);
        }

        $queue = $this->locator->queueFor($transition);
        if ($queue) {
            $this->logger->debug('[AsyncQueueRouting] stamping transport', [
                'transition' => $transition,
                'transport'  => $queue,
            ]);
            $envelope = $envelope->with(new TransportNamesStamp([$queue]));
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
