<?php

declare(strict_types=1);

namespace Survos\StateBundle\Twig\Components;

use Survos\StateBundle\Traits\MarkingInterface;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\Transition;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'state:workflow-marking',
    template: '@SurvosState/components/workflow_marking.html.twig',
)]
final class WorkflowMarkingComponent
{
    public MarkingInterface $subject;

    public string $size = 'sm';

    public bool $showMarking = true;

    public bool $showTransitions = true;

    public function __construct(
        private readonly Registry $workflowRegistry,
    ) {
    }

    public function getMarking(): ?string
    {
        return $this->subject->getMarking();
    }

    /**
     * @return list<array{name: string, label: string}>
     */
    public function getTransitions(): array
    {
        $workflow = $this->workflowRegistry->get($this->subject);
        $metadata = $workflow->getDefinition()->getMetadataStore();

        $transitions = [];
        foreach ($workflow->getEnabledTransitions($this->subject) as $transition) {
            assert($transition instanceof Transition);
            $meta = $metadata->getTransitionMetadata($transition);
            $transitions[] = [
                'name' => $transition->getName(),
                'label' => is_string($meta['label'] ?? null) ? $meta['label'] : $transition->getName(),
            ];
        }

        return $transitions;
    }
}
