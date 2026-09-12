<?php

declare(strict_types=1);

namespace Survos\StateBundle\Messenger\Middleware;

use Survos\StateBundle\Message\BatchedTransitionMessage;
use Survos\StateBundle\Message\TransitionMessage;
use Survos\StateBundle\Service\AsyncQueueLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * On the way out, turns a TransitionMessage for a #[Transition(batch: N)] transition into a
 * BatchedTransitionMessage, whose only handler is BatchTransitionHandler (see
 * BatchedTransitionMessage for why it cannot stay a TransitionMessage). Every stamp comes along,
 * including the TransportNamesStamp dispatch sites add via AsyncQueueLocator::stamps(), so the
 * message lands on the transition's own queue.
 *
 * Swapping here means every dispatch site batches without changing: a place's `next`,
 * state:iterate, app code that builds a TransitionMessage by hand.
 *
 * Left alone: transitions without `batch`, anything with batching switched off
 * (survos_state.batch_enabled), and a subject a batch listener released (CONTEXT_UNBATCHED).
 *
 * Put on every bus by BatchTransitionMiddlewarePass (not a config prepend: see there).
 */
final class BatchTransitionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AsyncQueueLocator $locator,
        private readonly bool $batchEnabled = true,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $msg = $envelope->getMessage();
        if ($this->batchEnabled
            && $msg instanceof TransitionMessage
            && $envelope->last(ReceivedStamp::class) === null
            && $envelope->last(HandledStamp::class) === null
            && empty($msg->getContext()[BatchedTransitionMessage::CONTEXT_UNBATCHED])
            && $this->locator->batchSize((string) $msg->getWorkflow(), $msg->getTransitionName()) !== null) {
            $envelope = new Envelope(BatchedTransitionMessage::fromTransitionMessage($msg), array_merge([], ...array_values($envelope->all())));
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
