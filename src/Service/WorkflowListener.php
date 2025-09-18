<?php
declare(strict_types=1);

namespace Survos\StateBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Survos\StateBundle\Message\TransitionMessage;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Workflow\Attribute\AsCompletedListener;
use Symfony\Component\Workflow\Attribute\AsEnteredListener;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Event\EnteredEvent;
use Symfony\Component\Workflow\WorkflowInterface;
use Zenstruck\Messenger\Monitor\Stamp\DescriptionStamp;
use Zenstruck\Messenger\Monitor\Stamp\TagStamp;

final class WorkflowListener
{
    public function __construct(
        /** @var WorkflowInterface[] */
        #[AutowireLocator('workflow.state_machine')] private ServiceLocator $workflows,
        private WorkflowHelperService $workflowHelperService,
        private PropertyAccessorInterface $propertyAccessor,
        private MessageBusInterface $messageBus,
        private EntityManagerInterface $entityManager,
        private AsyncQueueLocator $asyncQueueLocator,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    #[AsEnteredListener]
    public function onEntered(EnteredEvent $event): void
    {
        $subject  = $event->getSubject();
        $workflow = $this->workflowHelperService->getWorkflow($subject, $event->getWorkflowName());

        $currentPlace = array_keys($workflow->getMarking($subject)->getPlaces())[0] ?? null;
        $meta = $this->workflowHelperService->getPlaceMetadata($currentPlace, $workflow);
        if (!$currentPlace) {
            return;
        }

        $next = (array) ($event->getMetadata('next', $currentPlace) ?? []);
        foreach ($next as $transition) {
            if (!$workflow->can($subject, $transition)) {
                continue;
            }
            $this->dispatchTransition($workflow, $subject, $transition, $currentPlace);
            // Sequential semantics: stop after first applicable next
            break;
        }
    }

    #[AsCompletedListener]
    public function onCompleted(CompletedEvent $event): void
    {
        $subject   = $event->getSubject();
        $workflow  = $this->workflowHelperService->getWorkflow($subject, $event->getWorkflowName());
        $fromTrans = $event->getTransition();

        $next = (array) ($event->getMetadata('next', $fromTrans) ?? []);
        foreach ($next as $transition) {
            if (!$workflow->can($subject, $transition)) {
                $this->logger->info("Skipping transition $subject::class $transition");
                continue;
            }
            $currentPlace = array_keys($workflow->getMarking($subject)->getPlaces())[0] ?? '(unknown)';
            $this->dispatchTransition($workflow, $subject, $transition, $currentPlace);
            // Sequential semantics: stop after first applicable next
            break;
        }
    }

    private function dispatchTransition(WorkflowInterface $workflow, object $subject, string $transition, ?string $atPlace = null): void
    {
        $stamps = [];
        $workflowName = $workflow->getName();

        // add queue stamp automatically if async
        if ($isAsync = $this->asyncQueueLocator->isAsync($workflowName, $transition)) {
            $stamps = array_merge($stamps, $this->asyncQueueLocator->stampsFor($workflowName, $transition));
        }

        if (class_exists(TagStamp::class)) {
            $stamps[] = new TagStamp($transition);
        }
        if (class_exists(DescriptionStamp::class)) {
            $short = (new \ReflectionClass($subject))->getShortName();
            $id    = $this->resolveId($subject);
            $stamps[] = new DescriptionStamp(sprintf('Next/%s-%s @%s: %s', $short, (string)$id, (string)$atPlace, $transition));
        }

        $id = $this->resolveId($subject);
        if ($id === null) {
            $this->logger->warning('WorkflowListener: cannot resolve id for subject', ['class' => $subject::class]);
            return;
        }

        if ($isAsync) {
            $this->entityManager->flush();
        }

        $message = new TransitionMessage(
            $id,
            $subject::class,
            $transition,
            $workflow->getName()
        );

        // If async, flush before queuing so downstream sees persisted state
        $this->messageBus->dispatch($message, $stamps);
    }

    private function resolveId(object $entity): string|int|null
    {
        // Try "id" via PropertyAccessor?
        try {
            $val = $this->propertyAccessor->getValue($entity, 'id');
            if ($val !== null) {
                return $val;
            }
        } catch (\Throwable) {
            // fall back to Doctrine metadata
        }

        try {
            $meta = $this->entityManager->getClassMetadata($entity::class);
            $ids  = $meta->getIdentifierValues($entity);
            if (!$ids) {
                return null;
            }
            if (\count($ids) === 1) {
                return (string) \array_values($ids)[0];
            }
            return json_encode($ids, JSON_UNESCAPED_SLASHES) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
