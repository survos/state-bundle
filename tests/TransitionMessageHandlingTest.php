<?php

declare(strict_types=1);

namespace Survos\StateBundle\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Survos\StateBundle\Message\TransitionMessage;
use Survos\StateBundle\Service\WorkflowHelperService;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition;

final class TransitionMessageHandlingTest extends TestCase
{
    public function testCustomMarkingStoreAndStaleRedelivery(): void
    {
        // No getMarking(): the workflow owns access to this subject's state.
        $subject = new class {
            public string $status = 'draft';
        };
        $events = new EventDispatcher();
        $calls = [];
        $events->addListener('workflow.article.transition.publish', static function (TransitionEvent $event) use (&$calls): void {
            $calls[] = $event->getContext();
        });
        $workflow = new StateMachine(
            new Definition(['draft', 'published'], [new Transition('publish', 'draft', 'published')], 'draft'),
            new MethodMarkingStore(true, 'status'),
            $events,
            'article',
        );
        $defaultManager = $this->createMock(EntityManagerInterface::class);
        $defaultManager->expects(self::never())->method('find');
        $defaultManager->expects(self::never())->method('flush');
        $subjectManager = $this->createMock(EntityManagerInterface::class);
        $subjectManager->expects(self::exactly(2))->method('find')->with($subject::class, '42')->willReturn($subject);
        $subjectManager->expects(self::once())->method('flush');
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($subjectManager);
        $helper = new WorkflowHelperService(
            new ServiceLocator(['article' => static fn () => $workflow]),
            $defaultManager,
            $registry,
            $this->createStub(PropertyAccessorInterface::class),
            [],
        );
        $message = new TransitionMessage('42', $subject::class, 'publish', 'article', ['request' => 'test']);

        $result = $helper->handleTransition($message);
        self::assertSame('published', $subject->status);
        self::assertSame('draft', $result['initialMarking']);
        self::assertSame(['published'], $result['marking']);
        self::assertSame([['request' => 'test']], $calls);
        self::assertIsString(json_encode($result, JSON_THROW_ON_ERROR));

        // A sequential stale delivery is skipped; this does not promise concurrency safety.
        $stale = $helper->handleTransition($message);
        self::assertSame('published', $stale['initialMarking']);
        self::assertNotEmpty($stale['message']);
        self::assertCount(1, $calls);
    }

    public function testDeletedSubjectWithNoOptionalLogger(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::once())->method('find')->willReturn(null);
        $manager->expects(self::never())->method('flush');
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($manager);
        $helper = new WorkflowHelperService(new ServiceLocator([]), $manager, $registry, $this->createStub(PropertyAccessorInterface::class), []);

        $this->expectException(\Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('Missing stdClass 42 for transition "publish"');
        $helper->handleTransition(new TransitionMessage('42', \stdClass::class, 'publish', 'article'));
    }
}
