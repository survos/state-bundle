<?php

declare(strict_types=1);

namespace Survos\StateBundle\Event;

/**
 * Every subject of one batch of a batched transition, delivered once, before the transition is
 * applied to each of them. Dispatched under the name {@see self::name()}; listen with
 * #[AsBatchTransitionListener(workflow: …, transition: …)].
 *
 * A listener that cannot handle a particular subject (a task that is not batchable, a row
 * missing data) has two ways out:
 *  - skip($subject): the transition is applied to it right here, in this batch, but without the
 *    `batched` context flag, so its per-item listener does the work the old way. For cheap work.
 *  - release($subject): the transition is NOT applied here; the subject is re-dispatched as an
 *    ordinary TransitionMessage and handled on its own, in parallel with its peers, by whichever
 *    worker picks it up. For slow work (a sync API call per subject) that would otherwise run
 *    serially inside one batch while the rest of its group waits unacknowledged.
 */
final class BatchTransitionEvent
{
    /** @var array<string, true> spl_object_id => true */
    private array $skipped = [];
    /** @var array<string, true> spl_object_id => true */
    private array $released = [];

    /**
     * @param list<object>                        $subjects already checked with $workflow->can()
     * @param array<string, array<string, mixed>> $contexts per subject id: the dispatching message's context
     */
    public function __construct(
        public readonly string $workflowName,
        public readonly string $transitionName,
        public readonly array $subjects,
        public readonly array $contexts = [],
    ) {
    }

    public static function name(string $workflow, string $transition): string
    {
        return sprintf('state.batch.%s.%s', $workflow, $transition);
    }

    public function skip(object $subject): void
    {
        $this->skipped[(string) spl_object_id($subject)] = true;
    }

    public function isSkipped(object $subject): bool
    {
        return isset($this->skipped[(string) spl_object_id($subject)]);
    }

    public function release(object $subject): void
    {
        $this->released[(string) spl_object_id($subject)] = true;
    }

    public function isReleased(object $subject): bool
    {
        return isset($this->released[(string) spl_object_id($subject)]);
    }
}
