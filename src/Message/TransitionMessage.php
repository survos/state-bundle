<?php

declare(strict_types=1);

namespace Survos\StateBundle\Message;

class TransitionMessage
{
    public function __construct(
        public private(set) int|string $id,
        public private(set) string $className,
        public private(set) string $transitionName,
        public private(set) string $workflow,
        public array $context = [], // to pass around tags and other extra properties
    ) {
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function getId(): int|string
    {
        return $this->id;
    }

    public function getClassName(): string
    {
        return $this->className;
    }

    public function getWorkflow(): string
    {
        return $this->workflow;
    }

    public function getTransitionName(): string
    {
        return $this->transitionName;
    }
}
