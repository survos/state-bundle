<?php

namespace Survos\StateBundle\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
class Transition
{
    /**
     * @param bool|string|null $queue How this transition is dispatched.
     *   - `true`   — queued, on a transport the bundle names itself
     *                (`<workflow>.<transition>`, e.g. `item.suggest`).
     *   - a string — queued, on a transport of your choosing.
     *   - `false` / `null` — synchronous; the transition runs in the request.
     *
     *   `queue` replaces two older arguments that between them said the same
     *   thing: `transport`, which named a queue, and `async`, which said only
     *   whether there was one. Naming the queue was given up when the bundle
     *   learned to derive names, and `async` is what was left — a boolean that
     *   cannot express the thing its predecessor could. `queue` is both: pass
     *   `true` to take the derived name, a string to override it.
     *
     * @param ?string $transport Deprecated, use `queue: 'name'` (or `queue: false` for 'sync').
     * @param ?bool   $async     Deprecated, use `queue: true`.
     */
    public function __construct(
        public array|string $from,
        public array|string $to,
        public ?string $info = null,
        public ?string $description = null,
        public ?string $guard = null,
        public ?array $metadata = [],
        public ?string $transport = null,
        public ?bool $async = null,
        public ?array $next = [],
        // Appended rather than slotted in beside `async`, so that any existing
        // positional use of this attribute keeps meaning what it meant.
        public bool|string|null $queue = null,
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

        $this->queue = $this->resolveQueue();

        if ($this->queue === true) {
            $this->metadata['async'] = true;
        } elseif (is_string($this->queue) && $this->queue !== '') {
            // A named queue is still async; the name overrides the derived one.
            $this->metadata['async'] = true;
            $this->metadata['transport'] = $this->queue;
        }

        if ($this->next) {
            $this->metadata['next'] = $this->next;
        }
    }

    /**
     * Fold the deprecated arguments into `queue`.
     *
     * An explicit `queue` always wins, so a transition being migrated can carry
     * both for a release without changing behaviour.
     */
    private function resolveQueue(): bool|string|null
    {
        if ($this->async !== null) {
            trigger_deprecation(
                'survos/state-bundle',
                '2.25',
                'The "async" argument of #[Transition] is deprecated, use "queue: %s" instead.',
                $this->async ? 'true' : 'false',
            );
        }

        if ($this->transport !== null) {
            trigger_deprecation(
                'survos/state-bundle',
                '2.25',
                'The "transport" argument of #[Transition] is deprecated, use "queue: %s" instead.',
                $this->transport === 'sync' ? 'false' : sprintf("'%s'", $this->transport),
            );
        }

        if ($this->queue !== null) {
            return $this->queue;
        }

        // 'sync' was the one magic transport name: it meant "not queued".
        if ($this->transport !== null) {
            return $this->transport === 'sync' ? false : $this->transport;
        }

        return $this->async;
    }

    public function getFrom(): string|array
    {
        return $this->from;
    }

    /** True when this transition is dispatched rather than run inline. */
    public function isQueued(): bool
    {
        return $this->queue === true || (is_string($this->queue) && $this->queue !== '');
    }
}
