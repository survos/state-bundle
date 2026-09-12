<?php

namespace Survos\StateBundle\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
class Transition
{
    public function __construct(
        public array|string $from,
        public array|string $to,
        public ?string $info=null,
        public ?string $description=null,
        public ?string $guard=null,
        public ?array $metadata=[],
        public ?string $transport=null,
        public ?bool $async=null,
        public ?array $next=[],
        /**
         * Handle this transition in batches of up to N subjects instead of one message at a time.
         * Implies async. Listeners registered with #[AsBatchTransitionListener] receive every
         * subject of a batch at once (one provider job, one bulk push); the transition is then
         * applied to each subject with context ['batched' => true], so per-item
         * #[AsTransitionListener]s can tell the bulk work was already done. See
         * BatchTransitionHandler.
         */
        public ?int $batch=null,
    ) {
        if ($guard) {
            $this->metadata['guard'] = $guard;
        }
        if ($this->info) {
            $this->metadata['info'] = $this->info;
            $this->metadata['description'] = $this->info; // info is shorthand for description
        }
        if ($this->description) {
            $this->metadata['description'] = $this->description;
        }
        if ($this->async) {
            $this->metadata['async'] = true;
        }
        if ($this->transport) {
            $this->metadata['transport'] = $this->transport; // deprecated
            $this->metadata['async'] = ($this->transport <> 'sync');
        }
        if ($this->next) {
            $this->metadata['next'] = $this->next;
        }
        if ($this->batch !== null && $this->batch > 0) {
            $this->metadata['batch'] = $this->batch;
            $this->metadata['async'] = true;
        }

    }

    public function getFrom(): string|array
    {
        return $this->from;
    }
}
