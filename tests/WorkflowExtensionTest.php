<?php

declare(strict_types=1);

namespace Survos\StateBundle\Tests;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Survos\StateBundle\Service\WorkflowHelperService;
use Survos\StateBundle\Tests\Fixtures\Workflow\TestAssetWorkflowDefinition;
use Survos\StateBundle\Tests\Fixtures\Workflow\TestSubject;
use Survos\StateBundle\Twig\WorkflowExtension;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

final class WorkflowExtensionTest extends TestCase
{
    public function testWorkflowTwigFunctionsIncludeConstantResolvers(): void
    {
        $extension = new WorkflowExtension($this->createWorkflowHelperService());
        $names = array_map(fn($function) => $function->getName(), $extension->getFunctions());

        $this->assertContains('workflow_const', $names);
        $this->assertContains('workflow_name', $names);
    }

    public function testWorkflowConstDelegatesToHelper(): void
    {
        $extension = new WorkflowExtension($this->createWorkflowHelperService());
        $subject = new TestSubject();

        $this->assertSame('remove', $extension->getWorkflowConstant($subject, 'PLACE_REMOVE'));
        $this->assertSame('test_asset', $extension->getWorkflowName($subject));
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
