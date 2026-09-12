<?php

declare(strict_types=1);

namespace Survos\StateBundle\Messenger;

use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Survos\StateBundle\Event\BatchTransitionEvent;
use Survos\StateBundle\Message\BatchedTransitionMessage;
use Survos\StateBundle\Message\TransitionMessage;
use Survos\StateBundle\Service\AsyncQueueLocator;
use Survos\StateBundle\Service\WorkflowHelperService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Handler\Acknowledger;
use Symfony\Component\Messenger\Handler\BatchHandlerInterface;
use Symfony\Component\Messenger\Handler\BatchHandlerTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Handles transitions declared #[Transition(batch: N)]: collects their messages, and per group
 * (same class, workflow, transition) loads the subjects in one query, asks the workflow which
 * can move, hands those to #[AsBatchTransitionListener]s in ONE event -- one provider job, one
 * bulk push -- then applies the transition to each with context ['batched' => true].
 *
 * Why batching lives here and not in WorkflowHelperService::handleTransition: that handler
 * serves TransitionMessage from every transport, so a batch handler beside it would run every
 * transition twice. BatchTransitionMiddleware turns batched transitions into their own message
 * class, which this is the only handler for.
 *
 * Flushing: a group goes out when it reaches its transition's `batch` size, or when the worker
 * has been idle for the idle timeout (Symfony's BatchHandlerTrait), so a trickle still moves.
 * Dispatched synchronously (state:iterate --sync, sync mode), a message is handled at once as
 * a batch of one -- same listeners, same flags.
 *
 * Acks are per message: a subject that fails to apply is nacked alone and retried; a batch
 * listener that throws nacks its whole group, which Messenger retries. A subject the listener
 * released is acked here and re-dispatched as a plain TransitionMessage (CONTEXT_UNBATCHED).
 */
#[AsMessageHandler]
final class BatchTransitionHandler implements BatchHandlerInterface
{
    use BatchHandlerTrait;

    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly WorkflowHelperService $workflowHelper,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly AsyncQueueLocator $locator,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $defaultBatchSize = 100,
        private readonly int $idleTimeout = 5,
        /** Re-dispatches released subjects; without a bus a release degrades to skip(). */
        private readonly ?MessageBusInterface $bus = null,
    ) {
    }

    public function __invoke(BatchedTransitionMessage $message, ?Acknowledger $ack = null): mixed
    {
        return $this->handle($message, $ack);
    }

    /** Flush as soon as ANY group is full: groups carry their own transition's size. */
    private function shouldFlush(): bool
    {
        $counts = [];
        foreach ($this->jobs as [$message]) {
            $key = $message->groupKey();
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            if ($counts[$key] >= $this->sizeFor($message)) {
                return true;
            }
        }
        if ($this->idleTimeout > 0 && $this->lastMessageAt !== null) {
            return ((int) ($this->batchClock ?? \Symfony\Component\Clock\Clock::get())->now()->format('U') - $this->lastMessageAt) >= $this->idleTimeout;
        }

        return false;
    }

    private function getBatchSize(): int
    {
        return $this->defaultBatchSize;
    }

    private function getIdleTimeout(): ?int
    {
        return $this->idleTimeout > 0 ? $this->idleTimeout : null;
    }

    /** @param list<array{0: BatchedTransitionMessage, 1: Acknowledger}> $jobs */
    private function process(array $jobs): void
    {
        $groups = [];
        foreach ($jobs as $job) {
            $groups[$job[0]->groupKey()][] = $job;
        }
        foreach ($groups as $group) {
            $this->processGroup($group);
        }
    }

    /** @param non-empty-list<array{0: BatchedTransitionMessage, 1: Acknowledger}> $group */
    private function processGroup(array $group): void
    {
        /** @var BatchedTransitionMessage $first */
        $first = $group[0][0];
        $class = $first->className;
        $transition = $first->transitionName;
        $em = $this->managerRegistry->getManagerForClass($class);
        if ($em === null) {
            foreach ($group as [, $ack]) {
                $ack->nack(new \LogicException("No entity manager maps $class"));
            }

            return;
        }

        // One query for the whole group.
        $idField = $em->getClassMetadata($class)->getIdentifierFieldNames()[0] ?? 'id';
        $byId = [];
        foreach ($em->getRepository($class)->findBy([$idField => array_map(static fn (array $j) => $j[0]->id, $group)]) as $entity) {
            $byId[(string) $em->getClassMetadata($class)->getIdentifierValues($entity)[$idField]] = $entity;
        }

        $workflow = null;
        $ready = []; // [entity, message, ack]
        $seen = [];
        foreach ($group as [$message, $ack]) {
            $entity = $byId[(string) $message->id] ?? null;
            if ($entity === null) {
                $this->logger->error('batch {transition}: missing {class} {id}', ['transition' => $transition, 'class' => $class, 'id' => $message->id]);
                $ack->ack(['message' => "missing entity $class {$message->id}"]);
                continue;
            }
            if (isset($seen[(string) $message->id])) {
                // The same subject twice in one group (a double dispatch): one transition, not two.
                $ack->ack(['info' => "duplicate $transition {$message->id}"]);
                continue;
            }
            $seen[(string) $message->id] = true;
            $workflow ??= $this->workflowHelper->getWorkflow($entity, $message->workflow);
            if (!$workflow->can($entity, $transition)) {
                // Already moved (a redelivery, or another path got there first): nothing to do.
                $ack->ack(['info' => "cannot $transition {$message->id}"]);
                continue;
            }
            $ready[] = [$entity, $message, $ack];
        }
        if ($ready === []) {
            return;
        }

        $event = new BatchTransitionEvent(
            $first->workflow,
            $transition,
            array_map(static fn (array $r) => $r[0], $ready),
            array_combine(array_map(static fn (array $r) => (string) $r[1]->id, $ready), array_map(static fn (array $r) => $r[1]->context, $ready)),
        );
        try {
            $this->eventDispatcher->dispatch($event, BatchTransitionEvent::name($first->workflow, $transition));
        } catch (\Throwable $e) {
            $this->logger->error('batch {transition} listener failed for {n} {class}: {err}', ['transition' => $transition, 'n' => \count($ready), 'class' => $class, 'err' => $e->getMessage()]);
            foreach ($ready as [, , $ack]) {
                $ack->nack($e);
            }

            return;
        }

        $applied = [];
        foreach ($ready as [$entity, $message, $ack]) {
            if ($this->bus !== null && $event->isReleased($entity)) {
                // Back to the per-message path: handled alone, by WorkflowHelperService, on the
                // transition's own queue (stamped like every other dispatch site; unstamped it
                // would run right here, inline, which is what releasing is meant to avoid).
                try {
                    $single = new TransitionMessage($message->id, $message->className, $transition, $message->workflow,
                        [BatchedTransitionMessage::CONTEXT_UNBATCHED => true] + $message->context);
                    $this->bus->dispatch($single, $this->locator->stamps($single));
                    $ack->ack(['released' => $transition]);
                } catch (\Throwable $e) {
                    $ack->nack($e);
                }
                continue;
            }
            try {
                $context = $message->context;
                if (!$event->isSkipped($entity) && !$event->isReleased($entity)) {
                    $context[BatchedTransitionMessage::CONTEXT_BATCHED] = true;
                }
                $workflow->apply($entity, $transition, $context);
                $applied[] = $ack;
            } catch (\Throwable $e) {
                $this->logger->error('batch {transition}: apply failed for {id}: {err}', ['transition' => $transition, 'id' => $message->id, 'err' => $e->getMessage()]);
                $ack->nack($e);
            }
        }

        // One flush for the group; if it fails, none of the applied markings stuck.
        try {
            $em->flush();
        } catch (\Throwable $e) {
            $this->logger->error('batch {transition}: flush failed for {n} {class}: {err}', ['transition' => $transition, 'n' => \count($applied), 'class' => $class, 'err' => $e->getMessage()]);
            foreach ($applied as $ack) {
                $ack->nack($e);
            }

            return;
        }
        foreach ($applied as $ack) {
            $ack->ack(['applied' => $transition]);
        }
    }

    private function sizeFor(BatchedTransitionMessage $message): int
    {
        return $this->locator->batchSize($message->workflow, $message->transitionName) ?? $this->defaultBatchSize;
    }
}
