<?php

namespace Survos\StateBundle\Controller;

use Survos\StateBundle\Traits\MarkingInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Workflow\WorkflowInterface;

interface HandleTransitionsInterface
{
    public function handleTransitionButtons(
        ?WorkflowInterface $workflow=null,
        ?string $transition=null,
        ?MarkingInterface $entity=null,
        ?MessageBusInterface $bus = null,
    ): ?string;

    public function dispatchMessage(WorkflowInterface $workflow, $transition, MarkingInterface $entity);
}
