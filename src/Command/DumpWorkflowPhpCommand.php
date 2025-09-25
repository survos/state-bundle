<?php
declare(strict_types=1);

namespace Survos\StateBundle\Command;

use Survos\StateBundle\Attribute\Place;
use Survos\StateBundle\Attribute\Transition;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition as SfTransition;

#[AsCommand(
    name: 'state:workflow:dump-php',
    description: 'Generate a PHP interface with #[Place]/#[Transition] from a live workflow'
)]
final class DumpWorkflowPhpCommand
{
    /** @var array<string, WorkflowInterface> */
    private array $byName = [];

    public function __construct(
        #[AutowireIterator('workflow.workflow')]
        iterable $workflows = [],
        #[AutowireIterator('workflow.state_machine')]
        iterable $stateMachines = []
    ) {
        foreach ($workflows as $wf) {
            $this->byName[$this->inferName($wf)] = $wf;
        }
        foreach ($stateMachines as $wf) {
            $this->byName[$this->inferName($wf)] = $wf;
        }
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Workflow name to dump')] string $name,
        #[Option('Target PHP namespace', 'namespace')] string $namespace = 'App\\Workflow',
        #[Option('Interface name to generate (default derived from workflow name)', 'interface')] ?string $iface = null,
        #[Option('Write to path (otherwise stdout)', 'output')] ?string $output = null
    ): int {
        if (!isset($this->byName[$name])) {
            $io->error("Workflow '$name' not found.");
            return Command::FAILURE;
        }
        $wf = $this->byName[$name];
        $type = $wf instanceof StateMachine ? 'state_machine' : 'workflow';

        $def = $wf->getDefinition();
        $mds = $wf->getMetadataStore();

        $iface ??= $this->normalizeInterfaceName($name);
        $initials = $def->getInitialPlaces();
        $initialConst = ($type === 'state_machine' && $initials) ? ($initials[0] ?? null) : null;

        $placeConsts = [];
        foreach ($def->getPlaces() as $place) {
            $cName = $this->constify('PLACE_' . $place);
            $meta  = $mds->getPlaceMetadata($place) ?? [];
            [$placeArgs, $metaLeftovers] = $this->splitPlaceArgs($meta);

            $attrPieces = [];
            foreach ($placeArgs as $k => $v) {
                $attrPieces[] = $this->namedArg($k, $v);
            }
            if ($metaLeftovers) {
                $attrPieces[] = $this->namedArg('metadata', $metaLeftovers);
            }

            $attr = sprintf('#[Place(%s)]', implode(', ', $attrPieces));
            $placeConsts[] = sprintf("    %s\n    public const %s = '%s';\n", $attr, $cName, $place);
        }

        $transConsts = [];
        foreach ($def->getTransitions() as $t) {
            $tName = $t->getName() ?? $this->makeTransitionName($t);
            $cName = $this->constify('TRANSITION_' . $tName);

            $from = $t->getFroms();
            $to   = $t->getTos();
            $meta = $mds->getTransitionMetadata($t) ?? [];

            $guard = $meta['guard'] ?? null;
            unset($meta['guard']);

            $explicit = [];
            foreach (['info','description','async','next'] as $k) {
                if (\array_key_exists($k, $meta)) {
                    $explicit[$k] = $k === 'next' ? (array) $meta[$k] : $meta[$k];
                    unset($meta[$k]);
                }
            }

            $args = [
                $this->namedArg('from', $from),
                $this->namedArg('to',   $to),
            ];
            foreach ($explicit as $k => $v) {
                $args[] = $this->namedArg($k, $v);
            }
            if ($guard !== null) {
                $args[] = $this->namedArg('guard', $guard);
            }
            if ($meta) {
                $args[] = $this->namedArg('metadata', $meta);
            }

            $attr = sprintf('#[Transition(%s)]', implode(', ', $args));
            $transConsts[] = sprintf("    %s\n    public const %s = '%s';\n", $attr, $cName, $tName);
        }

        $lines = [];
        $lines[] = '<?php';
        $lines[] = 'declare(strict_types=1);';
        $lines[] = '';
        $lines[] = 'namespace ' . $namespace . ';';
        $lines[] = '';
        $lines[] = 'use Survos\\StateBundle\\Attribute\\Place;';
        $lines[] = 'use Survos\\StateBundle\\Attribute\\Transition;';
        $lines[] = '';
        $lines[] = sprintf('interface %s', $iface);
        $lines[] = '{';
        $lines[] = sprintf("    public const WORKFLOW_NAME = '%s';", $name);
        if ($initialConst) {
            $lines[] = '';
            $lines[] = '    // Initial marking for state machine';
            $lines[] = sprintf("    public const INITIAL = '%s';", $initialConst);
        }
        if ($placeConsts) {
            $lines[] = '';
            $lines[] = '    // Places';
            $lines[] = implode("\n", $placeConsts);
        }
        if ($transConsts) {
            $lines[] = '    // Transitions';
            $lines[] = implode("\n", $transConsts);
        }
        $lines[] = '}';
        $code = implode("\n", $lines) . "\n";

        if ($output) {
            @mkdir(\dirname($output), 0775, true);
            file_put_contents($output, $code);
            $io->success("Wrote PHP workflow interface to $output");
        } else {
            $io->writeln($code);
        }

        return Command::SUCCESS;
    }

    private function inferName(WorkflowInterface $wf): string
    {
        return method_exists($wf, 'getName') ? $wf->getName() : (new \ReflectionClass($wf))->getShortName();
    }

    private function normalizeInterfaceName(string $name): string
    {
        $studly = preg_replace_callback('/(^|_)([a-z])/i', fn($m) => strtoupper($m[2]), $name);
        return $studly . 'Workflow';
    }

    private function constify(string $raw): string
    {
        $c = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $raw));
        $c = preg_replace('/_{2,}/', '_', $c);
        return rtrim($c, '_');
    }

    /** @return array{0: array $placeArgs, 1: array $leftovers} */
    private function splitPlaceArgs(array $meta): array
    {
        $args = [];
        $left = $meta;

        foreach (['info','description','bgColor','next'] as $k) {
            if (\array_key_exists($k, $left)) {
                $args[$k] = $k === 'next' ? (array) $left[$k] : $left[$k];
                unset($left[$k]);
            }
        }

        return [$args, $left];
    }

    private function makeTransitionName(SfTransition $t): string
    {
        $from = implode('_', (array) $t->getFroms());
        $to   = implode('_', (array) $t->getTos());
        return 'from_' . $from . '_to_' . $to;
    }

    private function namedArg(string $name, mixed $value): string
    {
        return $name . ': ' . $this->export($value);
    }

    private function export(mixed $v): string
    {
        if (is_string($v)) {
            return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $v) . "'";
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_array($v)) {
            $isList = array_is_list($v);
            $parts = [];
            foreach ($v as $k => $vv) {
                $piece = $this->export($vv);
                $parts[] = $isList ? $piece : $this->exportArrayKey($k) . ' => ' . $piece;
            }
            return '[' . implode(', ', $parts) . ']';
        }
        if (is_int($v) || is_float($v) || $v === null) {
            return var_export($v, true);
        }
        return var_export($v, true);
    }

    private function exportArrayKey(string|int $k): string|int
    {
        if (is_int($k)) return $k;
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $k) . "'";
    }
}
