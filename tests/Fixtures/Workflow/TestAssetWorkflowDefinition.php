<?php

declare(strict_types=1);

namespace Survos\StateBundle\Tests\Fixtures\Workflow;

use Survos\StateBundle\Attribute\Place;
use Survos\StateBundle\Attribute\Transition;
use Survos\StateBundle\Attribute\Workflow;

#[Workflow(supports: [TestSubject::class], name: self::WORKFLOW_NAME)]
final class TestAssetWorkflowDefinition
{
    public const WORKFLOW_NAME = 'test_asset';

    #[Place(initial: true, info: 'New', bgColor: 'secondary')]
    public const PLACE_NEW = 'new';

    #[Place(info: 'Remove', bgColor: 'danger')]
    public const PLACE_REMOVE = 'remove';

    #[Transition(from: [self::PLACE_NEW], to: self::PLACE_REMOVE, info: 'Remove it')]
    public const TRANSITION_REMOVE = 'remove';
}
