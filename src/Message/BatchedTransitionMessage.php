<?php

declare(strict_types=1);

namespace Survos\StateBundle\Message;

/**
 * A TransitionMessage for a transition declared with #[Transition(batch: N)].
 *
 * Deliberately NOT a subclass of TransitionMessage. Messenger resolves handlers by class,
 * parent classes and interfaces, and WorkflowHelperService::handleTransition is a bare
 * #[AsMessageHandler] for TransitionMessage on every transport -- a subclass would be handled
 * by both, applying every transition twice. That is the trap lingua's TranslateBatchMessage
 * and harvest's deleted BatchResizeRequests both hit. A separate class has exactly one
 * handler: BatchTransitionHandler.
 *
 * Nobody constructs this directly: BatchTransitionMiddleware swaps a TransitionMessage for
 * one of these on the way out when its transition is batched, so every existing dispatch site
 * (workflow `next`, state:iterate, app code building TransitionMessage by hand) batches
 * without changing.
 */
final class BatchedTransitionMessage
{
    /** Context flag set on each subject's apply() after the batch listeners ran. */
    public const string CONTEXT_BATCHED = 'batched';

    /**
     * Context flag on a TransitionMessage that must NOT be batched: a subject a batch listener
     * released back to the per-message path. BatchTransitionMiddleware leaves it a TransitionMessage.
     */
    public const string CONTEXT_UNBATCHED = 'unbatched';

    public function __construct(
        private(set) int|string $id,
        private(set) string $className,
        private(set) string $transitionName,
        private(set) string $workflow,
        public array $context = [],
    ) {
    }

    public function getId(): int|string { return $this->id; }
    public function getClassName(): string { return $this->className; }
    public function getTransitionName(): string { return $this->transitionName; }
    public function getWorkflow(): string { return $this->workflow; }
    public function getContext(): array { return $this->context; }

    public static function fromTransitionMessage(TransitionMessage $message): self
    {
        return new self($message->getId(), $message->getClassName(), $message->getTransitionName(), (string) $message->getWorkflow(), $message->getContext());
    }

    public function toTransitionMessage(): TransitionMessage
    {
        return new TransitionMessage($this->id, $this->className, $this->transitionName, $this->workflow, $this->context);
    }

    /** Messages that belong in the same batch: same class, workflow and transition. */
    public function groupKey(): string
    {
        return $this->className . '|' . $this->workflow . '|' . $this->transitionName;
    }
}
