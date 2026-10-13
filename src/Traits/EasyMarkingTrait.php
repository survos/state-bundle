<?php

declare(strict_types=1);

namespace Survos\StateBundle\Traits;

use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use Symfony\Component\Workflow\WorkflowInterface;

trait EasyMarkingTrait
{
    protected WorkflowInterface $workflow;

    protected function getPlaces(): array
    {
        return $this->workflow->getDefinition()->getPlaces();
    }

    protected function markingFilter(string $propertyName = 'marking')
    {
        return ChoiceFilter::new($propertyName)
            ->setChoices($this->getPlaces()
            );
    }
}
