<?php
declare(strict_types=1);

namespace Survos\StateBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;

#[AsCommand(
    name: 'state:queues:dump',
    description: 'Show the async transition map and configured Messenger transports'
)]
final class StateQueuesDumpCommand extends Command
{
    public function __construct(
        private readonly ParameterBagInterface $params,
        #[Autowire(service: 'messenger.receiver_locator')]
        private readonly ?ServiceProviderInterface $receiverLocator = null,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Also list receiver service ids')] bool $showServices = false,
    ): int {
        $io->title('survos_state.async_transition_map');

        /** @var array<string, array<string, string>> $map */
        $map = $this->params->has('survos_state.async_transition_map')
            ? (array) $this->params->get('survos_state.async_transition_map')
            : [];

        if (!$map) {
            $io->writeln('  (empty)');
        } else {
            foreach ($map as $wf => $trs) {
                $io->section($wf);
                $t = new Table($io);
                $t->setHeaders(['transition', 'queue']);
                foreach ($trs as $tr => $queue) {
                    $t->addRow([$tr, $queue]);
                }
                $t->render();
            }
        }

        $io->newLine();
        $io->title('Messenger transports');

        if (!$this->receiverLocator) {
            $io->writeln('  (receiver locator not available)');
            return Command::SUCCESS;
        }

        $services = $this->receiverLocator->getProvidedServices(); // id => class-string|?null
        if (!$services) {
            $io->writeln('  (no transports discovered)');
            return Command::SUCCESS;
        }

        $t2 = new Table($io);
        $t2->setHeaders(['service id', 'queue_name (if exposed)']);

        foreach (array_keys($services) as $sid) {
            $queueName = '(n/a)';
            try {
                $svc = $this->receiverLocator->get($sid);
                // Best-effort: some receivers/transports expose details
                if (is_object($svc)) {
                    if (method_exists($svc, 'getQueueName')) {
                        /** @noinspection PhpUndefinedMethodInspection */
                        $queueName = (string) $svc->getQueueName();
                    } elseif (method_exists($svc, 'getOptions')) {
                        /** @noinspection PhpUndefinedMethodInspection */
                        $opts = $svc->getOptions();
                        if (is_array($opts) && isset($opts['queue_name'])) {
                            $queueName = (string) $opts['queue_name'];
                        }
                    }
                }
            } catch (\Throwable) {
                // ignore; leave (n/a)
            }
            $t2->addRow([$sid, $queueName]);
        }

        $t2->render();

        if ($showServices) {
            $io->newLine();
            $io->writeln('<info>Provided receiver services</info>');
            foreach ($services as $id => $class) {
                $io->writeln(sprintf('  • %s  (%s)', $id, $class ?? 'n/a'));
            }
        }

        return Command::SUCCESS;
    }
}
