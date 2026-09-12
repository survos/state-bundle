<?php

declare(strict_types=1);

namespace Survos\StateBundle\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;
use Survos\StateBundle\Attribute\Transition as TransitionAttribute;
use Survos\StateBundle\Event\BatchTransitionEvent;
use Survos\StateBundle\Message\BatchedTransitionMessage;
use Survos\StateBundle\Message\TransitionMessage;
use Survos\StateBundle\Messenger\BatchTransitionHandler;
use Survos\StateBundle\Messenger\Middleware\BatchTransitionMiddleware;
use Survos\StateBundle\Service\AsyncQueueLocator;
use Survos\StateBundle\Service\WorkflowHelperService;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\Acknowledger;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition;

final class BatchTransitionHandlerTest extends TestCase
{
    private EventDispatcher $dispatcher;
    /** @var array<string, BatchSubject> */
    private array $rows = [];
    private int $flushes = 0;
    /** @var list<list<string>> ids per batch-listener call */
    private array $batchCalls = [];
    /** @var array<string, bool> id => per-item listener saw the batched flag */
    private array $perItemBatched = [];

    protected function setUp(): void
    {
        $this->dispatcher = new EventDispatcher();
        $this->dispatcher->addListener(BatchTransitionEvent::name('batch_test', 'submit'), function (BatchTransitionEvent $e): void {
            $this->batchCalls[] = array_map(static fn (BatchSubject $s) => $s->id, $e->subjects);
        });
        $this->dispatcher->addListener('workflow.batch_test.transition.submit', function (TransitionEvent $e): void {
            $this->perItemBatched[$e->getSubject()->id] = (bool) ($e->getContext()[BatchedTransitionMessage::CONTEXT_BATCHED] ?? false);
        });
        foreach (['a', 'b', 'c', 'd'] as $id) {
            $this->rows[$id] = new BatchSubject($id);
        }
    }

    public function testTransitionAttributeBatchImpliesAsync(): void
    {
        $t = new TransitionAttribute(from: 'new', to: 'queued', batch: 500);
        self::assertSame(500, $t->metadata['batch']);
        self::assertTrue($t->metadata['async']);
        self::assertArrayNotHasKey('batch', (new TransitionAttribute(from: 'new', to: 'queued'))->metadata);
    }

    public function testMiddlewareSwapsBatchedTransitionsAndKeepsStamps(): void
    {
        $middleware = new BatchTransitionMiddleware($this->locator(['batch_test' => ['submit' => 3]]));

        // stamped the way dispatch sites stamp: AsyncQueueLocator::stamps() → TransportNamesStamp
        $out = $middleware->handle(new Envelope(new TransitionMessage('a', BatchSubject::class, 'submit', 'batch_test', ['k' => 'v']), [new DelayStamp(10), new TransportNamesStamp(['batch.test.submit'])]), new StackMiddleware());
        self::assertInstanceOf(BatchedTransitionMessage::class, $out->getMessage());
        self::assertSame(['k' => 'v'], $out->getMessage()->context);
        self::assertNotNull($out->last(DelayStamp::class), 'existing stamps survive the swap');
        self::assertSame(['batch.test.submit'], $out->last(TransportNamesStamp::class)?->getTransportNames());

        $plain = $middleware->handle(new Envelope(new TransitionMessage('a', BatchSubject::class, 'other', 'batch_test')), new StackMiddleware());
        self::assertInstanceOf(TransitionMessage::class, $plain->getMessage(), 'transitions without batch: stay TransitionMessage');
    }

    public function testGroupFlushesAtItsSizeWithOneListenerCallAndOneFlush(): void
    {
        $handler = $this->handler(size: 3);
        $acks = [];
        foreach (['a', 'b', 'c'] as $id) {
            $acks[$id] = new Acknowledger(BatchTransitionHandler::class);
            $handler(new BatchedTransitionMessage($id, BatchSubject::class, 'submit', 'batch_test'), $acks[$id]);
        }

        self::assertSame([['a', 'b', 'c']], $this->batchCalls, 'one listener call for the whole group');
        self::assertSame(1, $this->flushes, 'one flush for the group');
        foreach (['a', 'b', 'c'] as $id) {
            self::assertSame('queued', $this->rows[$id]->marking);
            self::assertTrue($this->perItemBatched[$id], "per-item listener sees batched=true for $id");
            self::assertTrue($acks[$id]->isAcknowledged());
            self::assertNull($acks[$id]->getError());
        }
    }

    public function testPartialGroupWaitsThenFlushesOnForce(): void
    {
        $handler = $this->handler(size: 3);
        $ack = new Acknowledger(BatchTransitionHandler::class);
        $handler(new BatchedTransitionMessage('a', BatchSubject::class, 'submit', 'batch_test'), $ack);
        self::assertSame([], $this->batchCalls, 'below batch size and not idle: buffered');

        $handler->flush(true); // what the worker does when it goes idle / stops
        self::assertSame([['a']], $this->batchCalls);
        self::assertTrue($ack->isAcknowledged());
    }

    public function testSynchronousDispatchIsABatchOfOne(): void
    {
        $this->handler(size: 3)(new BatchedTransitionMessage('d', BatchSubject::class, 'submit', 'batch_test'));
        self::assertSame([['d']], $this->batchCalls);
        self::assertSame('queued', $this->rows['d']->marking);
    }

    public function testSkippedSubjectIsAppliedWithoutTheBatchedFlag(): void
    {
        $this->dispatcher->addListener(BatchTransitionEvent::name('batch_test', 'submit'), function (BatchTransitionEvent $e): void {
            $e->skip($this->rows['b']);
        });
        $handler = $this->handler(size: 2);
        foreach (['a', 'b'] as $id) {
            $handler(new BatchedTransitionMessage($id, BatchSubject::class, 'submit', 'batch_test'), new Acknowledger(BatchTransitionHandler::class));
        }
        self::assertTrue($this->perItemBatched['a']);
        self::assertFalse($this->perItemBatched['b'], 'skipped subject: its per-item listener does the work');
        self::assertSame('queued', $this->rows['b']->marking);
    }

    public function testReleasedSubjectIsRedispatchedUnbatchedAndNotApplied(): void
    {
        $this->dispatcher->addListener(BatchTransitionEvent::name('batch_test', 'submit'), function (BatchTransitionEvent $e): void {
            $e->release($this->rows['b']);
        });
        $bus = new class implements MessageBusInterface {
            public array $sent = [];
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->sent[] = $message;

                return new Envelope($message, $stamps);
            }
        };
        $handler = $this->handler(size: 2, bus: $bus);
        $ackB = new Acknowledger(BatchTransitionHandler::class);
        $handler(new BatchedTransitionMessage('a', BatchSubject::class, 'submit', 'batch_test'), new Acknowledger(BatchTransitionHandler::class));
        $handler(new BatchedTransitionMessage('b', BatchSubject::class, 'submit', 'batch_test', ['k' => 'v']), $ackB);

        self::assertSame('queued', $this->rows['a']->marking);
        self::assertSame('new', $this->rows['b']->marking, 'released: not applied inside the batch');
        self::assertTrue($ackB->isAcknowledged());
        self::assertCount(1, $bus->sent);
        $sent = $bus->sent[0];
        self::assertInstanceOf(TransitionMessage::class, $sent);
        self::assertSame(['unbatched' => true, 'k' => 'v'], $sent->getContext());

        // ...and the middleware leaves that message a plain TransitionMessage.
        $out = (new BatchTransitionMiddleware($this->locator(['batch_test' => ['submit' => 2]])))->handle(new Envelope($sent), new StackMiddleware());
        self::assertInstanceOf(TransitionMessage::class, $out->getMessage());
    }

    public function testDuplicateMessagesForOneSubjectBatchItOnce(): void
    {
        $handler = $this->handler(size: 3);
        $acks = [];
        foreach (['a', 'a', 'b'] as $i => $id) {
            $acks[$i] = new Acknowledger(BatchTransitionHandler::class);
            $handler(new BatchedTransitionMessage($id, BatchSubject::class, 'submit', 'batch_test'), $acks[$i]);
        }
        self::assertSame([['a', 'b']], $this->batchCalls);
        foreach ($acks as $ack) {
            self::assertTrue($ack->isAcknowledged());
            self::assertNull($ack->getError());
        }
    }

    public function testBatchingSwitchedOffLeavesTransitionMessagesAlone(): void
    {
        $middleware = new BatchTransitionMiddleware($this->locator(['batch_test' => ['submit' => 3]]), batchEnabled: false);
        $out = $middleware->handle(new Envelope(new TransitionMessage('a', BatchSubject::class, 'submit', 'batch_test')), new StackMiddleware());
        self::assertInstanceOf(TransitionMessage::class, $out->getMessage());
    }

    public function testListenerFailureNacksTheWholeGroupAndMovesNothing(): void
    {
        $this->dispatcher->addListener(BatchTransitionEvent::name('batch_test', 'submit'), static function (): void {
            throw new \RuntimeException('provider down');
        });
        $handler = $this->handler(size: 2);
        $acks = ['a' => new Acknowledger(BatchTransitionHandler::class), 'b' => new Acknowledger(BatchTransitionHandler::class)];
        foreach ($acks as $id => $ack) {
            $handler(new BatchedTransitionMessage($id, BatchSubject::class, 'submit', 'batch_test'), $ack);
        }
        foreach ($acks as $id => $ack) {
            self::assertInstanceOf(\RuntimeException::class, $ack->getError(), "$id nacked for retry");
            self::assertSame('new', $this->rows[$id]->marking);
        }
        self::assertSame(0, $this->flushes);
    }

    public function testSubjectThatCannotMoveIsAckedAndLeftOutOfTheBatch(): void
    {
        $this->rows['a']->marking = 'queued'; // a redelivery: already moved
        $handler = $this->handler(size: 2);
        $ackA = new Acknowledger(BatchTransitionHandler::class);
        $handler(new BatchedTransitionMessage('a', BatchSubject::class, 'submit', 'batch_test'), $ackA);
        $handler(new BatchedTransitionMessage('b', BatchSubject::class, 'submit', 'batch_test'), new Acknowledger(BatchTransitionHandler::class));

        self::assertSame([['b']], $this->batchCalls);
        self::assertTrue($ackA->isAcknowledged());
        self::assertNull($ackA->getError());
    }

    private function handler(int $size, ?MessageBusInterface $bus = null): BatchTransitionHandler
    {
        $workflow = new StateMachine(
            new Definition(['new', 'queued'], [new Transition('submit', 'new', 'queued'), new Transition('other', 'new', 'queued')], 'new'),
            new MethodMarkingStore(true, 'marking'),
            $this->dispatcher,
            'batch_test',
        );

        $metadata = $this->createStub(ClassMetadata::class);
        $metadata->method('getIdentifierFieldNames')->willReturn(['id']);
        $metadata->method('getIdentifierValues')->willReturnCallback(static fn (BatchSubject $s) => ['id' => $s->id]);
        $repository = $this->createStub(ObjectRepository::class);
        $repository->method('findBy')->willReturnCallback(fn (array $criteria) => array_values(array_intersect_key($this->rows, array_flip($criteria['id']))));
        $em = $this->createStub(ObjectManager::class);
        $em->method('getClassMetadata')->willReturn($metadata);
        $em->method('getRepository')->willReturn($repository);
        $em->method('flush')->willReturnCallback(function (): void { ++$this->flushes; });
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);

        $helper = new WorkflowHelperService(
            new ServiceLocator(['batch_test' => static fn () => $workflow]),
            $this->createStub(EntityManagerInterface::class),
            $registry,
            $this->createStub(PropertyAccessorInterface::class),
            [],
        );

        return new BatchTransitionHandler($registry, $helper, $this->dispatcher, $this->locator(['batch_test' => ['submit' => $size]]), idleTimeout: 0, bus: $bus);
    }

    private function locator(array $batchMap, array $queueMap = []): AsyncQueueLocator
    {
        return new AsyncQueueLocator($queueMap, [], $this->createStub(EntityManagerInterface::class), $batchMap);
    }
}

final class BatchSubject
{
    public string $marking = 'new';

    public function __construct(public string $id)
    {
    }

    public function getMarking(): string
    {
        return $this->marking;
    }

    public function setMarking(string $marking, array $context = []): void
    {
        $this->marking = $marking;
    }
}
