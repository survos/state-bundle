<?php

declare(strict_types=1);

namespace Survos\StateBundle\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Survos\StateBundle\Message\TransitionMessage;
use Survos\StateBundle\Service\AsyncQueueLocator;
use Survos\StateBundle\Service\WorkflowHelperService;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Starts the workflow for a newly persisted entity.
 *
 * `#[Place(initial: true, next: [...])]` has always MEANT "when an entity is
 * created in this place, dispatch the first of these whose guard passes" — the
 * attribute says so itself:
 *
 *     public ?array $next=null, // only if initial:true
 *
 * but nothing ever implemented it. WorkflowListener drives `next` from the
 * `entered` and `completed` events, and neither fires for an initial place: an
 * entity constructed in `new` never *transitions* into it. So every app had to
 * hand-roll the kickoff, and mediary's AssetRegistry::dispatch() did exactly
 * that — hardcoding one transition, with no fallback, duplicating a decision
 * AssetFlow already declares. That hand-roll is why adding a guard to the iiif
 * transition stalled every asset in `new` instead of falling through to
 * archive (survos-sites/mediary#7).
 *
 * postPersist collects, postFlush dispatches. It has to be postFlush: the
 * TransitionMessage carries only an id, so the row must be committed before the
 * message is visible to a worker — with a doctrine transport the message and
 * the entity would otherwise race inside the same flush.
 *
 * Opt-in by construction: an initial place with no `next` is left alone, so
 * this cannot start cascading in an app that never asked for it.
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postFlush)]
final class InitialPlaceKickoffListener
{
    /** @var list<object> entities persisted in this flush that may need a kickoff */
    private array $pending = [];

    /**
     * postFlush re-entrancy guard. Dispatching to a doctrine transport writes
     * to messenger_messages and flushes again, which re-enters this listener.
     * Without this the second pass would re-read $pending and dispatch twice —
     * the same double-dispatch class of bug this method exists to remove.
     */
    private bool $dispatching = false;

    public function __construct(
        private readonly WorkflowHelperService $workflowHelperService,
        private readonly AsyncQueueLocator     $asyncQueueLocator,
        private readonly MessageBusInterface   $messageBus,
        private readonly LoggerInterface       $logger = new NullLogger(),
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->pending[] = $args->getObject();
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->dispatching || $this->pending === []) {
            return;
        }

        $entities      = $this->pending;
        $this->pending = [];

        $this->dispatching = true;
        try {
            foreach ($entities as $entity) {
                $this->kickoff($entity, $args);
            }
        } finally {
            $this->dispatching = false;
        }
    }

    private function kickoff(object $entity, PostFlushEventArgs $args): void
    {
        $workflow = $this->workflowFor($entity);
        if (!$workflow instanceof WorkflowInterface) {
            return;
        }

        $definition = $workflow->getDefinition();
        $initial    = $definition->getInitialPlaces()[0] ?? null;
        if ($initial === null) {
            return;
        }

        // Only entities actually sitting in the initial place. A row persisted
        // mid-pipeline (an import that sets its own marking, a re-persist) has
        // already been kicked off and must not be restarted.
        $places = array_keys($workflow->getMarking($entity)->getPlaces());
        if (($places[0] ?? null) !== $initial) {
            return;
        }

        $next = (array) ($workflow->getMetadataStore()->getPlaceMetadata($initial)['next'] ?? []);
        if ($next === []) {
            return;
        }

        foreach ($next as $transition) {
            if (!$workflow->can($entity, $transition)) {
                continue;
            }

            $id = $this->identifierFor($entity, $args);
            if ($id === null) {
                $this->logger->warning('workflow kickoff: cannot resolve id for {class}', [
                    'class' => $entity::class,
                ]);

                return;
            }

            $message = new TransitionMessage($id, $entity::class, $transition, $workflow->getName());
            $this->messageBus->dispatch($message, $this->asyncQueueLocator->stamps($message));

            $this->logger->info('workflow kickoff: {class} {id} @{place} → {transition}', [
                'class' => $entity::class,
                'id' => $id,
                'place' => $initial,
                'transition' => $transition,
            ]);

            // Sequential semantics, matching WorkflowListener: the FIRST
            // applicable transition, not every applicable one.
            return;
        }

        $this->logger->info('workflow kickoff: no applicable transition for {class} @{place}', [
            'class' => $entity::class,
            'place' => $initial,
        ]);
    }

    private function workflowFor(object $entity): ?WorkflowInterface
    {
        $class = \Doctrine\Common\Util\ClassUtils::getRealClass($entity::class);
        $name  = $this->workflowHelperService->getWorkflowsGroupedByClass()[$class][0] ?? null;
        if ($name === null) {
            return null;
        }

        try {
            return $this->workflowHelperService->getWorkflow($entity, $name);
        } catch (\Throwable $e) {
            $this->logger->warning('workflow kickoff: cannot resolve workflow {name} for {class}: {err}', [
                'name' => $name,
                'class' => $class,
                'err' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function identifierFor(object $entity, PostFlushEventArgs $args): string|int|null
    {
        try {
            $ids = $args->getObjectManager()->getClassMetadata($entity::class)->getIdentifierValues($entity);
        } catch (\Throwable) {
            return null;
        }

        if ($ids === []) {
            return null;
        }

        return \count($ids) === 1
            ? (string) array_values($ids)[0]
            : (json_encode($ids, JSON_UNESCAPED_SLASHES) ?: null);
    }
}
