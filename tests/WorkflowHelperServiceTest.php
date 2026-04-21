<?php

declare(strict_types=1);

namespace Survos\StateBundle\Tests;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Survos\StateBundle\Service\WorkflowHelperService;
use Survos\StateBundle\Tests\Fixtures\Workflow\TestAssetWorkflowDefinition;
use Survos\StateBundle\Tests\Fixtures\Workflow\TestSubject;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

final class WorkflowHelperServiceTest extends TestCase
{
    public function testWorkflowConstantsResolveFromSubjectAndWorkflowName(): void
    {
        $service = $this->createWorkflowHelperService();
        $subject = new TestSubject();

        $this->assertSame(TestAssetWorkflowDefinition::class, $service->getWorkflowDefinitionClass($subject));
        $this->assertSame('test_asset', $service->getWorkflowNameForSubjectOrWorkflow($subject));
        $this->assertSame('remove', $service->getWorkflowConstant($subject, 'PLACE_REMOVE'));
        $this->assertSame('remove', $service->getWorkflowConstant('test_asset', 'TRANSITION_REMOVE'));
    }

    public function testWorkflowConstantThrowsForUnknownConstant(): void
    {
        $service = $this->createWorkflowHelperService();

        $this->expectException(\InvalidArgumentException::class);
        $service->getWorkflowConstant('test_asset', 'PLACE_MISSING');
    }

    private function createWorkflowHelperService(): WorkflowHelperService
    {
        return new WorkflowHelperService(
            new ServiceLocator([]),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(PropertyAccessorInterface::class),
            [
                'test_asset' => [
                    'supports' => [TestSubject::class],
                ],
            ],
            [
                'by_workflow' => [
                    'test_asset' => TestAssetWorkflowDefinition::class,
                ],
                'by_support' => [
                    TestSubject::class => [TestAssetWorkflowDefinition::class],
                ],
            ],
            $this->createMock(LoggerInterface::class),
        );
    }
}
