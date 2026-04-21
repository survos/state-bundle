<?php

declare(strict_types=1);

namespace Survos\StateBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\StateBundle\Config\AttributesWorkflowConfigBuilder;
use Survos\StateBundle\Tests\Fixtures\Workflow\TestAssetWorkflowDefinition;
use Survos\StateBundle\Tests\Fixtures\Workflow\TestSubject;

final class AttributesWorkflowConfigBuilderTest extends TestCase
{
    public function testBuildPublishesWorkflowDefinitionMappings(): void
    {
        $built = AttributesWorkflowConfigBuilder::build([__DIR__ . '/Fixtures/Workflow']);

        $this->assertArrayHasKey('test_asset', $built['workflows']);
        $this->assertSame(
            TestAssetWorkflowDefinition::class,
            $built['definition_classes']['by_workflow']['test_asset']
        );
        $this->assertSame(
            [TestAssetWorkflowDefinition::class],
            $built['definition_classes']['by_support'][TestSubject::class]
        );
        $this->assertSame(
            'remove',
            $built['workflows']['test_asset']['transitions'][0]['name']
        );
    }
}
