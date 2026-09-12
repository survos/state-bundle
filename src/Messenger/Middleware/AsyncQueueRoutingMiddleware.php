<?php
declare(strict_types=1);

namespace Survos\StateBundle\Messenger\Middleware;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Survos\StateBundle\Message\BatchedTransitionMessage;
use Survos\StateBundle\Message\TransitionMessage;
use Survos\StateBundle\Service\AsyncQueueLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * If a TransitionMessage is async (per AsyncQueueLocator) and no transport is set, stamp the
 * transition's own queue via TransportNamesStamp.
 *
 * NOT ON ANY BUS, so none of this runs today. Routing is done by the dispatch sites, which stamp
 * via AsyncQueueLocator::stamps(); this class is the unused net under the ones that forget, and
 * activating it is a deliberate behavior change. Read the note in SurvosStateBundle::loadExtension()
 * before wiring it up -- in particular, do it with a compiler pass like
 * BatchTransitionMiddlewarePass, never a framework.messenger config prepend.
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

        // BatchedTransitionMessage: the swap happened upstream, in BatchTransitionMiddleware.
        if (!$msg instanceof TransitionMessage && !$msg instanceof BatchedTransitionMessage) {
            return $stack->next()->handle($envelope, $stack);
        }

        // Respect explicit sender choice
        if ($envelope->last(TransportNamesStamp::class)) {
            return $stack->next()->handle($envelope, $stack);
        }

        $transition = $msg->getTransitionName();
        if (!$this->locator->isAsync($msg->getWorkflow(), $transition)) {
            // not async → sync fallback
            return $stack->next()->handle($envelope, $stack);
        }

        $queue = $this->locator->queueFor($msg->getWorkflow(), $transition);
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
