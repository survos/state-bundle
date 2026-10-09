<?php

declare(strict_types=1);

namespace Survos\StateBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\StateBundle\Attribute\Workflow;
use Survos\StateBundle\Config\AttributesWorkflowConfigBuilder;

final class WorkflowInitialPlaceTest extends TestCase
{
    public function testConstantInitialPlaceOverridesLegacyPlaceFlagEvenForZero(): void
    {
        $built = AttributesWorkflowConfigBuilder::build([__DIR__.'/Fixtures/InitialPlace']);
        self::assertSame('0', $built['workflows']['explicit_initial']['initial_marking']);
    }

    public function testStringBackedEnumAndLegacyInitialAreSupported(): void
    {
        self::assertSame('draft', (new Workflow(initialPlace: InitialStatus::Draft))->initial);
        self::assertSame('draft', (new Workflow(initial: 'draft'))->initial);
        self::assertSame(['draft', 'review'], (new Workflow(type: 'workflow', initial: ['draft', 'review']))->initial);
        $built = AttributesWorkflowConfigBuilder::build([__DIR__.'/Fixtures/Workflow']);
        self::assertSame('new', $built['workflows']['test_asset']['initial_marking']);
    }

    public function testConflictingOptionsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Workflow(initial: 'draft', initialPlace: 'review');
    }

    public function testIntegerBackedEnumIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Workflow(initialPlace: IntegerStatus::Draft);
    }
}

enum InitialStatus: string
{
    case Draft = 'draft';
}

enum IntegerStatus: int
{
    case Draft = 1;
}
