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

    /** inline: badge + available buttons. table: full transition table with blocked rows. */
    public string $layout = 'inline';

    /** Set to enable inline Apply buttons (uses the debug apply route). */
    public ?string $globalKey = null;

    public ?string $workflowCode = null;

    /** URL to redirect to after a successful transition. Defaults to current page if omitted. */
    public ?string $redirectUrl = null;

    public function __construct(
        private readonly Registry $workflowRegistry,
    ) {
    }

    public function getMarking(): ?string
    {
        return $this->subject->getMarking();
    }

    public function getPlaceMeta(): array
    {
        $marking = $this->subject->getMarking();
        if ($marking === null) {
            return [];
        }
        return $this->workflowRegistry->get($this->subject)
            ->getMetadataStore()
            ->getPlaceMetadata($marking);
    }

    /**
     * @return list<array{name: string, label: string, description: string|null, guard: string|null, enabled: bool}>
     */
    public function getTransitions(): array
    {
        $workflow   = $this->workflowRegistry->get($this->subject);
        $store      = $workflow->getDefinition()->getMetadataStore();

        $enabledMap = [];
        foreach ($workflow->getEnabledTransitions($this->subject) as $t) {
            $enabledMap[$t->getName()] = true;
        }

        $seen        = [];
        $transitions = [];
        foreach ($workflow->getDefinition()->getTransitions() as $transition) {
            $name = $transition->getName();
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $meta = $store->getTransitionMetadata($transition);
            $transitions[] = [
                'name'        => $name,
                'label'       => is_string($meta['label'] ?? null) ? $meta['label'] : $name,
                'description' => $meta['description'] ?? null,
                'guard'       => $meta['guard'] ?? null,
                'enabled'     => isset($enabledMap[$name]),
            ];
        }

        return $transitions;
    }
}
