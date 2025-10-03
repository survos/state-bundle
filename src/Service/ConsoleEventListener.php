<?php
declare(strict_types=1);

namespace Survos\StateBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Survos\StateBundle\Message\TransitionMessage;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Workflow\Attribute\AsCompletedListener;
use Symfony\Component\Workflow\Attribute\AsEnteredListener;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Event\EnteredEvent;
use Symfony\Component\Workflow\WorkflowInterface;
use Zenstruck\Messenger\Monitor\Stamp\DescriptionStamp;
use Zenstruck\Messenger\Monitor\Stamp\TagStamp;

final class ConsoleEventListener
{
    public function __construct(
        /** @var WorkflowInterface[] */
        private WorkflowHelperService $workflowHelperService,
        private MessageBusInterface $messageBus,
        private EntityManagerInterface $entityManager,
        private AsyncQueueLocator $asyncQueueLocator,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    #[AsEventListener(event: ConsoleEvents::COMMAND, priority: 0)]
    public function onCommand(ConsoleCommandEvent $event): void
    {
        $cmd = $event->getCommand();
        $input = $event->getInput();
        $output = $event->getOutput();

        if ($input->hasArgument('sync') && $input->getArgument('sync')) {
            $this->asyncQueueLocator->sync = true;
        }

        return;

        // Example: only act on certain commands
        // if (!in_array($cmd?->getName(), ['app:important', 'app:tei:import:all'], true)) {
        //     return;
        // }

        $output->writeln(sprintf(
            '<info>→ Starting %s</info> (args: %s) options: %s',
            $cmd?->getName() ?? '(unknown)',
            json_encode($input->getArguments(), JSON_UNESCAPED_SLASHES),
            json_encode($input->getOptions(), JSON_UNESCAPED_SLASHES),
        ));
    }

    /**
     * Fires if an exception occurs anywhere during execution.
     * You can transform the exception and/or tweak the exit code.
     */
    #[AsEventListener(event: ConsoleEvents::ERROR, priority: 0)]
    public function onError(ConsoleErrorEvent $event): void
    {
        $e = $event->getError();
        $output = $event->getOutput();

        $output->writeln('');
        $output->writeln('<error>✖ Console error:</error>');
        $output->writeln(sprintf('<comment>%s</comment>', $e::class));
        $output->writeln($e->getMessage());

        // Example: normalize exit code
        $event->setExitCode(1);

        // Example: wrap/replace exception (optional)
        // $event->setError(new \RuntimeException('Wrapped: '.$e->getMessage(), 0, $e));
    }

    /**
     * Always fires at the end. You can adjust the exit code here.
     */
    #[AsEventListener(event: ConsoleEvents::TERMINATE, priority: 0)]
    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        $cmd = $event->getCommand();
        $output = $event->getOutput();
        $code = $event->getExitCode();

        $output->writeln(sprintf(
            '<info>✓ Finished %s</info> with exit code <comment>%d</comment>',
            $cmd?->getName() ?? '(unknown)',
            $code
        ));

        // Example: upgrade success to a specific non-zero code, or coerce failures:
        // if ($code !== 0 && $cmd?->getName() === 'app:tei:import:all') {
        //     $event->setExitCode(2);
        // }
    }
}
