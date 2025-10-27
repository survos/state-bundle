<?php
declare(strict_types=1);

namespace Survos\StateBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(
    name: 'state:workflow:dump-yaml',
    description: 'Dump current workflows as Symfony YAML (places/transition metadata correctly placed)'
)]
final class DumpWorkflowsYamlCommand
{
    /** @var iterable<WorkflowInterface> */
    private iterable $workflows;

    public function __construct(
        #[AutowireIterator('workflow.workflow')]
        iterable $workflows = [],
        #[AutowireIterator('workflow.state_machine')]
        iterable $stateMachines = []
    ) {
        // merge both iterables
        $this->workflows = (static function(iterable ...$its): \Generator {
            foreach ($its as $it) {
                foreach ($it as $w) {
                    yield $w;
                }
            }
        })($workflows, $stateMachines);
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Filter by workflow name', 'workflow')] ?string $workflow = null,
        #[Option('Write YAML to this file path (otherwise stdout)', 'output')] ?string $output = null
    ): int {
        $root = ['framework' => ['workflows' => []]];
        $count = 0;

        foreach ($this->workflows as $wf) {
            $name = $this->inferName($wf);
            if ($workflow && $workflow !== $name) {
                continue;
            }

            $type = $wf instanceof StateMachine ? 'state_machine' : 'workflow';
            $def  = $wf->getDefinition();
            $mds  = $wf->getMetadataStore();

            $node = [
                'type' => $type,
                'marking_store' => [
                    'type' => 'method',
                    'property' => $type === 'state_machine' ? 'marking' : 'currentPlaces',
                ],
            ];

            if ($wfMeta = $mds->getWorkflowMetadata()) {
                $node['metadata'] = $wfMeta;
            }

            $initials = $def->getInitialPlaces();
            if ($initials) {
                $node['initial_marking'] = $type === 'state_machine' ? ($initials[0] ?? null) : array_values($initials);
            }

            $places = [];
            foreach ($def->getPlaces() as $place) {
                $pm = $mds->getPlaceMetadata($place) ?? [];
                $places[$place] = $pm ? ['metadata' => $pm] : [];
            }
            $node['places'] = $places;

            $transitionsYaml = [];
            foreach ($def->getTransitions() as $t) {
                $tName = method_exists($t, 'getName') ? $t->getName() : null;
                $froms = method_exists($t, 'getFroms') ? $t->getFroms() : [];
                $tos   = method_exists($t, 'getTos') ? $t->getTos() : [];
                $tMeta = $mds->getTransitionMetadata($t) ?? [];

                $guard = $tMeta['guard'] ?? null;
                unset($tMeta['guard']);

                $entry = [
                    'from' => \is_array($froms) && \count($froms) === 1 ? $froms[0] : array_values($froms),
                    'to'   => \is_array($tos)   && \count($tos)   === 1 ? $tos[0]   : array_values($tos),
                ];
                if ($guard) {
                    $entry['guard'] = $guard;
                }
                if ($tMeta) {
                    $entry['metadata'] = $tMeta;
                }

                if ($tName) {
                    $transitionsYaml[$tName] = $entry;
                } else {
                    $transitionsYaml[] = $entry;
                }
            }
            $node['transitions'] = $transitionsYaml;

            $root['framework']['workflows'][$name] = $node;
            $count++;
        }

        if ($count === 0) {
            $io->warning($workflow ? "No workflow named '$workflow' is registered." : 'No workflows found.');
            return Command::SUCCESS;
        }

        $yaml = Yaml::dump($root, 7, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);

        if ($output) {
            @mkdir(\dirname($output), 0775, true);
            file_put_contents($output, $yaml);
            $io->success("Wrote workflow YAML to $output");
        } else {
            $io->writeln($yaml);
        }

        return Command::SUCCESS;
    }

    private function inferName(WorkflowInterface $wf): string
    {
        return method_exists($wf, 'getName') ? $wf->getName() : (new \ReflectionClass($wf))->getShortName();
    }
}
