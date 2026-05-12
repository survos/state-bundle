<?php

namespace Survos\StateBundle\Command;

use Roave\BetterReflection\BetterReflection;
use Survos\StateBundle\Service\SurvosGraphVizDumper;
use Survos\StateBundle\Service\WorkflowHelperService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Component\Workflow\Transition;
use Twig\Environment;

#[AsCommand('survos:workflow:viz', 'Visualize a workflow')]
final class VizCommand
{
    private const DUMP_FORMAT_OPTIONS = ['puml', 'mermaid', 'dot'];

    private array $orderedEvents = [
        'guard',
        'leave',
        'transition',
        'enter',
        'entered',
        'completed',
        'announce',
    ];

    public function __construct(
        /** @var WorkflowInterface[] */
        private iterable $workflows,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        private Environment $twig,
        private WorkflowHelperService $workflowHelper,
        #[Autowire(service: 'event_dispatcher')]
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('A workflow name')] ?string $name = null,
        #[Argument('A marking (a list of places)')] array $marking = [],
        #[Option('Label a graph')] ?string $label = null,
        #[Option('Include metadata')] bool $withMetadata = false,
        #[Option('The dump format [puml|mermaid|dot]')] string $dumpFormat = 'dot',
    ): int {
        $allEvents = $this->getWorkflowListeners();

        if (!file_exists('doc/assets')) {
            mkdir('doc/assets', 0777, true);
        }

        $collected = [];
        $seen      = [];

        foreach ($this->workflows as $workflow) {
            $fn = $this->dumpSvg($workflow);
            $io->writeln($fn);
            $wfName = $workflow->getName();

            if ($name && $name !== $wfName) {
                continue;
            }

            $definition = $workflow->getDefinition();
            $mdStore = $definition->getMetadataStore();

            foreach ($definition->getTransitions() as $transition) {
                $transMeta = $mdStore->getTransitionMetadata($transition);
                /** @var Transition $transition */
                $tn = $transition->getName();

                foreach ($this->orderedEvents as $action) {
                    $eventKey = sprintf('workflow.%s.%s.%s', $wfName, $action, $tn);
                    $event = $allEvents[$eventKey] ?? null;
                    if (empty($event)) {
                        continue;
                    }

                    foreach ($event as $e) {
                        $e = (object) $e;
                        if (!str_starts_with($e->class, 'App\\')) {
                            continue;
                        }

                        $handlerKey = sprintf(
                            '%s::%s::%s::%s::%s',
                            $wfName,
                            $tn,
                            $action,
                            $e->class,
                            $e->name
                        );
                        if (isset($seen[$handlerKey])) {
                            continue;
                        }
                        $seen[$handlerKey] = true;

                        $refMethod = new \ReflectionMethod($e->class, $e->name);
                        $br        = (new BetterReflection())->reflector()->reflectClass($e->class);
                        $method    = $br->getMethod($e->name);

                        $srcLines = explode(
                            "\n",
                            str_replace("\t", "    ", $br->getLocatedSource()->getSource())
                        );
                        $snippet   = array_slice(
                            $srcLines,
                            $method->getStartLine() - 1,
                            $method->getEndLine() - $method->getStartLine() + 1
                        );
                        $justified = $this->leftJustifyPhpCode($snippet);
                        $file      = $br->getFileName();
                        $lineLink  = sprintf(
                            '%s/blob/main/%s#L%d-L%d',
                            basename($this->projectDir),
                            substr($file, strlen($this->projectDir) + 1),
                            $refMethod->getStartLine(),
                            $refMethod->getEndLine()
                        );

                        $collected[$wfName][$tn][$action][] = [
                            'file'     => $file,
                            'link'     => $lineLink,
                            'source'   => $justified,
                            'method'   => $e->name,
                            'metadata' => $transMeta,
                        ];
                    }
                }
            }
        }

        foreach ($collected as $wf => $transitions) {
            $md = $this->twig->render('@SurvosState/md/workflows.html.twig', [
                'workflowName'       => $wf,
                'eventsByTransition' => $transitions,
            ]);
            $outFile = sprintf('doc/%s.md', $wf);
            file_put_contents($outFile, $md);
            $io->writeln(sprintf('<info>Wrote</info> %s', $outFile));
        }

        return Command::SUCCESS;
    }

    private function leftJustifyPhpCode(array $lines): string
    {
        $minIndent = null;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            preg_match('/^[ \t]*/', $line, $m);
            $len = strlen($m[0]);
            if ($minIndent === null || $len < $minIndent) {
                $minIndent = $len;
            }
        }

        return implode("\n", array_map(
            fn(string $line) => preg_replace('/^[ \t]{0,' . $minIndent . '}/', '', $line),
            $lines
        ));
    }

    private function dumpSvg(WorkflowInterface $workflow): string
    {
        $dumper  = new SurvosGraphVizDumper();
        $marking = new Marking();
        $options = [
            'name'          => $workflow->getName(),
            'with-metadata' => true,
            'nofooter'      => true,
            'label'         => $workflow->getName(),
        ];
        $dot = $dumper->dump($workflow->getDefinition(), $marking, $options);

        file_put_contents(sprintf('doc/assets/%s.dot', $workflow->getName()), $dot);
        try {
            $process = new Process(['dot', '-Tsvg']);
            $process->setInput($dot);
            $process->mustRun();

            $svg = $process->getOutput();
            $fn  = sprintf('doc/assets/%s.svg', $workflow->getName());
            file_put_contents($fn, $svg);
        } catch (\Exception $e) {
            dd($e->getMessage(), $dot);
        }

        return $fn;
    }

    private function getWorkflowListeners(): array
    {
        $listeners = $this->dispatcher->getListeners();

        $workflowListeners = array_filter(
            $listeners,
            fn($key) => str_starts_with($key, 'workflow.'),
            ARRAY_FILTER_USE_KEY
        );

        $result = [];
        foreach ($workflowListeners as $eventName => $listenerList) {
            foreach ($listenerList as $listener) {
                if (is_array($listener)) {
                    [$objectOrClass, $method] = $listener;
                    $class = is_object($objectOrClass) ? get_class($objectOrClass) : $objectOrClass;
                    $result[$eventName][] = [
                        'class' => $class,
                        'name'  => $method,
                    ];
                }
            }
        }

        return $result;
    }

    private function describeListener(callable $listener): string
    {
        if (is_array($listener)) {
            [$classOrObject, $method] = $listener;
            $className = is_object($classOrObject) ? get_class($classOrObject) : (string) $classOrObject;
            return sprintf('%s::%s', $className, $method);
        }

        if ($listener instanceof \Closure) {
            $ref = new \ReflectionFunction($listener);
            return sprintf('Closure at %s:%d', $ref->getFileName(), $ref->getStartLine());
        }

        if (is_object($listener)) {
            return get_class($listener);
        }

        return (string) $listener;
    }
}
