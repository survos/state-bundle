<?php

declare(strict_types=1);

namespace Survos\StateBundle\Tests\Fixtures\InitialPlace;

use Survos\StateBundle\Attribute\Place;
use Survos\StateBundle\Attribute\Workflow;

#[Workflow(name: 'explicit_initial', initialPlace: self::ZERO)]
final class ExplicitInitialWorkflow
{
    #[Place(initial: true)]
    public const LEGACY = 'legacy';

    #[Place]
    public const ZERO = '0';
}
