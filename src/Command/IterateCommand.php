<?php

namespace Survos\StateBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Survos\FieldBundle\Repository\QueryBuilderHelperInterface;
use Survos\StateBundle\Event\RowEvent;
use Survos\StateBundle\Message\TransitionMessage;
use Survos\StateBundle\Service\AsyncQueueLocator;
use Survos\StateBundle\Service\WorkflowHelperService;
use Survos\StateBundle\Util\QueryFilters;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;
use Zenstruck\Alias;
use Zenstruck\Messenger\Monitor\Stamp\DescriptionStamp;
use Zenstruck\Messenger\Monitor\Stamp\TagStamp;

/**
 * The state:* commands that work on a workflow-enabled Doctrine entity: state:iterate dispatches
 * transitions, state:stats only reports. One class, so they share class resolution, workflow
 * lookups and the --filter syntax (Survos\StateBundle\Util\QueryFilters).
 */
final class IterateCommand
{
    public function __construct(
        private LoggerInterface $logger,
        private WorkflowHelperService $workflowHelperService,
        private EventDispatcherInterface $eventDispatcher,
        private MessageBusInterface $bus,
        private EntityManagerInterface $entityManager,
        private ManagerRegistry $doctrine,
        private PropertyAccessorInterface $propertyAccessor,
        private AsyncQueueLocator $asyncQueueLocator,
        #[Autowire('%env(DEFAULT_TRANSPORT)%')] private ?string $defaultTransport = null,
    ) {
    }

    #[AsCommand('state:iterate', 'Iterate a Doctrine entity and dispatch workflow transitions.', aliases: ['iterate'])]
    public function iterate(
        SymfonyStyle $io,

        // ARGUMENTS — description only (name inferred from parameter)
        #[Argument('FQCN or short name of the Doctrine entity')] ?string $className = null,

        // OPTIONS — description first; name inferred from parameter; explicit shortcut
        #[Option('Workflow transition name', shortcut: 't')] ?string $transition = null,
        #[Option('Comma-separated marking(s) to filter', shortcut: 'm')] ?string $marking = null,
        #[Option('Workflow name/code if multiple on class', shortcut: 'w')] ?string $workflowName = null,
        #[Option('Comma-separated tags for listeners', shortcut: 'g')] string $tags = '',
        #[Option('Comma-separated property paths to dump for each row', shortcut: 'd')] string $dump = '',
        #[Option('grid:index after flush?')] ?bool $indexAfterFlush = null,
        #[Option('filter using urlQuerystring style')] string $filter='',
        #[Option('Deprecated: use state:stats <class>. Show counts per marking and exit', shortcut: 's')] ?bool $stats = null,
        #[Option('force sync (no queues)', shortcut: 'y')] ?bool $sync = null,
        #[Option('What to do with chained transitions: async|sync|none. Default: sync when --sync, else async.')] ?string $cascade = null,
        #[Option('limit the number of records')] int $limit = 0,
        #[Option('Use this count for progress bar', shortcut: 'c')] int $count = 0,
        #[Option('Entity manager name')] ?string $em = null,
    ): int {
        // --limit shim
//        if ($limit) {
//            $io->warning('--limit is deprecated; use --max.');
//            $max = $limit;
//        }
        // Resolve cascade mode. Default preserves backward compat:
        //   --sync alone                → cascade=sync (today's behavior)
        //   no --sync                   → cascade=async (don't surprise async runs)
        //   --cascade=… overrides
        $cascadeMode = $cascade ?? ($sync ? 'sync' : 'async');
        if (!in_array($cascadeMode, ['async', 'sync', 'none'], true)) {
            $io->error(sprintf('--cascade must be async|sync|none, got "%s"', $cascadeMode));
            return Command::FAILURE;
        }
        // Sync transport: messenger dispatches resolve in-process. Drives both
        // --sync alone (cascade=sync) and an explicit --cascade=sync.
        if ($cascadeMode === 'sync') {
            $this->asyncQueueLocator->sync = true;
        }

        $filters = $this->parseFilters($filter);


        // Resolve/select entity class
        $doctrineEntitiesFqcn = $this->getAllDoctrineEntitiesFqcn();
        if (!$doctrineEntitiesFqcn) {
            $io->error('No Doctrine entities found. Create some first, then run again.');
            return Command::FAILURE;
        }

        if (!$className) {
            $className = $io->choice('Which Doctrine entity are you going to iterate?', array_values($doctrineEntitiesFqcn));
        } else {
            if (isset($doctrineEntitiesFqcn[$className])) {
                $className = $doctrineEntitiesFqcn[$className];
            }
            if (!class_exists($className) && class_exists('App\\Entity\\' . $className)) {
                $className = 'App\\Entity\\' . $className;
            }
            if (!class_exists($className) && class_exists(Alias::class)) {
                $className = Alias::classFor($className);
            }
        }

        if (!class_exists($className)) {
            $io->error("Entity class not found: {$className}");
            return Command::FAILURE;
        }

        // Use the EM that actually maps this class — DatasetInfo, for instance, lives on the 'dataset'
        // EM, not the default. Honour --em, else auto-resolve from the class, else fall back to default.
        $entityManager = $em
            ? $this->doctrine->getManager($em)
            : ($this->doctrine->getManagerForClass($className) ?? $this->entityManager);

        /** @var QueryBuilderHelperInterface $repo */
        $repo = $entityManager->getRepository($className);
        $qb = $entityManager->createQueryBuilder()
            ->select('e')
            ->from($className, 'e');

        // Determine workflow (if any) for this class
        $workflow = null;
        $availableTransitions = [];

        $grouped = $this->workflowHelperService->getWorkflowsGroupedByClass();
        $workflowNames = $this->workflowNamesForClass($className, $grouped);
        if (isset($workflowNames[0])) {
            $workflowName ??= $workflowNames[0];
            $workflow = $this->workflowHelperService->getWorkflowByCode($workflowName);

            // Build from->transitions map and list of places
            $places = array_values($workflow->getDefinition()->getPlaces());
            foreach ($workflow->getDefinition()->getTransitions() as $t) {
                foreach ($t->getFroms() as $from) {
                    $availableTransitions[$from][] = $t;
                }
            }

            if ($stats) {
                $this->showStats($io, $className, $availableTransitions, $workflow);
                return Command::SUCCESS;
            }

            // Pick marking(s)
            if ($marking) {
                $selectedMarkings = array_values(array_filter(array_map('trim', explode(',', $marking))));
                foreach ($selectedMarkings as $m) {
                    if (!in_array($m, $places, true)) {
                        $io->error("Invalid marking: {$m}\nValid markings are:\n - " . implode("\n - ", $places));
                        return Command::FAILURE;
                    }
                }
            } else {
                $question = new ChoiceQuestion('From which marking?', $places);
                $marking = $io->askQuestion($question);
                $selectedMarkings = [$marking];
            }

            // Pick transition (if not provided). $marking may be a comma-separated list (e.g.
            // "raw,normalized,enriched,folio" to re-trigger regardless of current state) — match
            // against any of the selected markings, not the raw unsplit string (which never
            // equals a single place name and used to make every multi-marking -t lookup fail).
            $transitions = [];
            foreach ($workflow->getDefinition()->getTransitions() as $t) {
                if (array_intersect($selectedMarkings, $t->getFroms()) !== []) {
                    $help = $this->wfTransitionDescription($workflow, $t) ?? $t->getName();
                    if ($guard = $this->wfTransitionGuard($workflow, $t)) {
                        $help .= " (if: {$guard})";
                    }
                    $transitions[$t->getName()] = $help;
                }
            }

            if ($transition) {
                if (!array_key_exists($transition, $transitions)) {
                    $io->error("Invalid transition: {$transition}\nValid from '{$marking}':\n - " . implode("\n - ", array_keys($transitions)));
                    return Command::FAILURE;
                }
            } else {
                $question = new ChoiceQuestion('Transition?', array_keys($transitions));
                $transition = $io->askQuestion($question);
            }
        }

        $io->title($className);

        // Build where (supports IN() for multiple markings)
        $where = $filters;
        if ($marking) {
            $where['marking'] = array_values(array_filter(array_map('trim', explode(',', $marking))));
        }

        // Determine total count using the same filter semantics as iteration.
        if (!$count) {
            $countQb = $entityManager->createQueryBuilder()
                ->select('COUNT(e)')
                ->from($className, 'e');
            $this->applyWhereFilters($countQb, $where);
            $count = (int) $countQb->getQuery()->getSingleScalarResult();
            if (!$count) {
                $io->warning('No items found for filter: ' . json_encode($where));
                return Command::SUCCESS;
            }
        }

        $progressBar = new ProgressBar($io, $count);

        // Prepare stamps
        $stamps = [];
        $shortClass = (new \ReflectionClass($className))->getShortName();

        if ($workflow && $transition) {

////            $this->bus->dispatch(new TransitionMessage(...), $stamps);
//
//            $wfMeta = $this->workflowHelperService->getTransitionMetadata($transition, $workflow);
//            assert($wfMeta['transport']);
//            $transport ??= $wfMeta['transport'] ?? $this->defaultTransport;
        }
//        if ($transport) {
//            $stamps[] = new TransportNamesStamp([$transport]);
//        }
        if (class_exists(TagStamp::class)) {
            $stamps[] = new TagStamp($transition ?? 'iterate');
        }

        // Optional dump table
        $table = null;
        $headers = [];
        if ($dump) {
            $headers = array_values(array_unique(array_filter(array_map('trim', explode(',', $dump)))));
            if (!in_array('key', $headers, true)) {
                $headers[] = 'key';
            }
            $table = new Table($io);
            $table->setHeaders($headers);
        }

        // Build query
//        $qb = $entityManager->getRepository($className)->createQueryBuilder('t');
        $this->applyWhereFilters($qb, $where);

        // Identifier handling (single id only)
        $classMeta = $entityManager->getClassMetadata($className);
        $idFields = $classMeta->getIdentifierFieldNames();
        if (count($idFields) !== 1) {
            $io->error('Composite identifiers are not supported by this command.');
            return Command::FAILURE;
        }
        $identifier = $idFields[0];

        // PRE event
        $this->eventDispatcher->dispatch(new RowEvent(
            $className,
            type: RowEvent::PRE_ITERATE,
            action: self::class,
        ));

        // Iterate
        $processed = 0;
        $failures = 0;

        foreach ($qb->getQuery()->toIterable() as $item) {
            $key = $this->propertyAccessor->getValue($item, $identifier);

            if ($table) {
                $row = [];
                foreach ($headers as $h) {
                    $value = $h === 'key'
                        ? $key
                        : $this->propertyAccessor->getValue($item, $h);
                    $row[] = substr((string)($value ?? ''), 0, 120);
                }
                $table->addRow($row);
            }

            if ($workflow && $transition) {
                if (!$workflow->can($item, $transition)) {
                    foreach ($workflow->buildTransitionBlockerList($item, $transition) as $blocker) {
                        $io->warning($blocker->getMessage());
                    }
                    $progressBar->advance();
                    $processed++;
                    if ($limit && $processed >= $limit) {
                        break;
                    }
                    continue;
                }

//                $messageStamps = $stamps;
//                if (class_exists(DescriptionStamp::class)) {
//                    $messageStamps[] = new DescriptionStamp("{$shortClass}:{$key} {$marking}->{$transition}");
//                }

//                if ($this->asyncQueueLocator->isAsync($workflowName, $transition)) {
//                    $stamps = array_merge($stamps, $this->asyncQueueLocator->stampsFor($workflowName, $transition));
//                }

                if ($sync) {
                    // Direct apply() — skip the messenger wrapper for THIS transition
                    // so listener stack traces / dd()s land in this process. The
                    // cascade context tells WorkflowListener whether to dispatch
                    // chained transitions ('none' suppresses, 'sync'/'async' both
                    // dispatch normally; transport mode is handled separately).
                    try {
                        $workflow->apply($item, $transition, ['cascade' => $cascadeMode]);
                        $entityManager->flush();
                    } catch (\Throwable $e) {
                        // One bad entity must not abort a bulk run — log, count, continue. A failed
                        // flush closes Doctrine's EM, so reset it before the next iteration.
                        $failures++;
                        $io->writeln(sprintf('  <error>skip %s: %s</error>', (string) $key, $e->getMessage()));
                        if (!$entityManager->isOpen()) {
                            $this->doctrine->resetManager($em);
                            $entityManager = $em
                                ? $this->doctrine->getManager($em)
                                : ($this->doctrine->getManagerForClass($className) ?? $this->entityManager);
                        }
                    }
                } else {
                    $msg = new TransitionMessage($key, $className, $transition, $workflowName);
                    $stamps = $this->asyncQueueLocator->stamps($msg);
                    $this->bus->dispatch($msg, $stamps);
                }
            } else {
                // No workflow: emit a row event and let listeners handle it
                $this->eventDispatcher->dispatch(new RowEvent(
                    $className,
                    $item,
                    $key,
                    $processed,
                    $count,
                    type: RowEvent::LOAD,
                    action: self::class,
                    context: [
                        'tags' => $tags ? explode(',', $tags) : [],
                        'transition' => $transition
                    ]
                ));
            }

            $processed++;
            if ($limit && ($processed >= $limit)) {
                break;
            }

            // Optional: free memory on big runs (beware: detaches entities)
            if (($processed % 200) === 0) {
                $entityManager->clear();
            }

            $progressBar->advance();
        }

        $progressBar->finish();

        if ($failures > 0) {
            $io->newLine(2);
            $io->warning(sprintf('%d entit%s failed and were skipped (continued past them).', $failures, $failures === 1 ? 'y' : 'ies'));
        }

        if ($table) {
            $table->render();
        }

        // POST event
        $this->eventDispatcher->dispatch(new RowEvent(
            $className,
            type: RowEvent::POST_LOAD,
            action: self::class,
            context: [
                'tags' => $tags ? explode(',', $tags) : [],
                'transition' => $transition
            ]
        ));

        // Optional stats if a workflow was in play
        if ($workflow && $stats) {
            $this->showStats($io, $className, $availableTransitions, $workflow);
        }

        $io->success(self::class . ' success ' . $className);
        return Command::SUCCESS;
    }

    private function getAllDoctrineEntitiesFqcn(): array
    {
        $entitiesFqcn = [];
        foreach ($this->doctrine->getManagers() as $entityManager) {
            $classesMetadata = $entityManager->getMetadataFactory()->getAllMetadata();
            foreach ($classesMetadata as $classMetadata) {
                $shortName = $classMetadata->getReflectionClass()->getShortName();
                $fqcn = $classMetadata->getName();
                $entitiesFqcn[$shortName] = $fqcn;
                $entitiesFqcn[lcfirst($shortName)] = $fqcn;
            }
        }
        return $entitiesFqcn;
    }

    private function wfTransitionDescription(WorkflowInterface $workflow, Transition $t): ?string
    {
        $store = $workflow->getMetadataStore();
        if (method_exists($store, 'getTransitionMetadata')) {
            $meta = $store->getTransitionMetadata($t);
            return $meta['description'] ?? null;
        }
        return $store->getMetadata('description', $t) ?? null;
    }

    private function wfTransitionInfo(WorkflowInterface $workflow, Transition $t): ?string
    {
        $store = $workflow->getMetadataStore();
        if (method_exists($store, 'getTransitionMetadata')) {
            $meta = $store->getTransitionMetadata($t);
            return $meta['info'] ?? null;
        }
        return $store->getMetadata('info', $t) ?? null;
    }

    private function wfTransitionGuard(WorkflowInterface $workflow, Transition $t): ?string
    {
        $store = $workflow->getMetadataStore();
        if (method_exists($store, 'getTransitionMetadata')) {
            $meta = $store->getTransitionMetadata($t);
            return $meta['guard'] ?? null;
        }
        return $store->getMetadata('guard', $t) ?? null;
    }

    public function showStats(
        SymfonyStyle $io,
        string $className,
        array $availableTransitions,
        WorkflowInterface $workflow
    ): void {
        $counts = $this->workflowHelperService->getCounts($className, 'marking');
        $table = new Table($io);
        $table->setHeaderTitle($className);
        $table->setHeaders(['marking', 'description', 'count', 'Available Transitions']);

        $store = $workflow->getMetadataStore();

        foreach ($counts as $name => $count) {
            // Place description
            $markingHelp = null;
            if (method_exists($store, 'getPlaceMetadata')) {
                $pm = $store->getPlaceMetadata($name);
                $markingHelp = $pm['info'] ?? null;
            } else {
                $markingHelp = $store->getMetadata('info', $name) ?? null;
            }

            $lines = [];
            foreach ($availableTransitions[$name] ?? [] as $t) {
                $desc = $this->wfTransitionInfo($workflow, $t);
                $lines[] = sprintf('(%s) %s', $t->getName(), $desc ?? '');
            }

            $table->addRow([$name, $markingHelp, $count, implode("\n", $lines)]);
        }

        $table->render();
    }

    /**
     * @param array<class-string, list<string>> $grouped
     * @return list<string>
     */
    private function workflowNamesForClass(string $className, array $grouped): array
    {
        if (isset($grouped[$className])) {
            return $grouped[$className];
        }

        $matches = [];
        foreach ($grouped as $supportedClass => $workflowNames) {
            if (is_a($className, $supportedClass, true)) {
                $matches = array_merge($matches, $workflowNames);
            }
        }

        return array_values(array_unique($matches));
    }

    private function applyWhereFilters($qb, array $filters): void
    {
        QueryFilters::apply($qb, $filters);
    }

    private function parseFilters(?string $filterString): array
    {
        return QueryFilters::parse($filterString);
    }

    private function getManagerForClass(string $className): EntityManagerInterface
    {
        $manager = $this->doctrine->getManagerForClass($className);
        if (!$manager) {
            throw new \InvalidArgumentException("No entity manager found for class: $className");
        }
        return $manager;
    }

    /**
     * Where things are in a workflow: a count per place, with each place's description and the
     * transitions that lead out of it. Read-only, and the replacement for `state:iterate --stats`.
     *
     * To expose it as an MCP tool with survos/command-bundle, opt in per app:
     *
     *     survos_command:
     *         agent_tools:
     *             - { command: 'state:stats', readOnly: true }
     */
    #[AsCommand('state:stats', 'Count entities per workflow place (marking), with each place\'s description and outgoing transitions', help: <<<'HELP'
        Without a class, summarises every entity that has a workflow. With one, lists every place of its
        workflow (empty places included), how many entities are in it, and which transitions lead out of it.
        Use it to see whether a pipeline is moving or stuck, and which transition would move a stuck place on.
        Narrow it to a tenant or project with a filter on the entity's own fields, e.g. "project=1".
        HELP)]
    public function stats(
        SymfonyStyle $io,
        #[Argument('Entity class, full or short, e.g. "Article" or "App\Entity\Article"; omit to summarise all')] ?string $className = null,
        #[Option('Workflow name, when the class has more than one')] ?string $workflowName = null,
        #[Option('Only count entities matching these field filters, query-string style, e.g. "project=1" or "host=%example%"')] string $filter = '',
        #[Option('Output format: text or json')] string $format = 'text',
    ): int {
        $grouped = $this->workflowHelperService->getWorkflowsGroupedByClass();
        $filters = QueryFilters::parse($filter);

        if (null === $className) {
            if ($filters) {
                throw new \InvalidArgumentException('A filter needs a class: fields differ between entities.');
            }
            $classes = [];
            foreach ($grouped as $class => $workflowNames) {
                try {
                    $classes[] = $this->describe($class, $workflowNames[0], false);
                } catch (\LogicException $e) {
                    // One misconfigured entity must not hide the rest of the summary.
                    $classes[] = ['class' => $class, 'workflow' => $workflowNames[0], 'total' => 0, 'markings' => [], 'problem' => $e->getMessage()];
                }
            }

            return $this->emit($io, $format, ['classes' => $classes], function () use ($io, $classes): void {
                $io->table(['class', 'workflow', 'total', 'markings'], array_map(fn (array $c) => [
                    $c['class'], $c['workflow'], $c['total'],
                    $c['problem'] ?? (implode(', ', array_map(fn (array $m) => $m['marking'].': '.$m['count'], array_filter($c['markings'], fn (array $m) => $m['count'] > 0))) ?: '-'),
                ], $classes));
            });
        }

        $class = $this->resolveClass($className, array_keys($grouped));
        if (null === $class) {
            throw new \InvalidArgumentException(sprintf('No workflow for "%s". Classes with a workflow: %s.', $className, implode(', ', array_keys($grouped)) ?: 'none'));
        }
        $workflowName ??= $grouped[$class][0];
        if (!in_array($workflowName, $grouped[$class], true)) {
            throw new \InvalidArgumentException(sprintf('"%s" has no workflow "%s". Its workflows: %s.', $class, $workflowName, implode(', ', $grouped[$class])));
        }
        $result = $this->describe($class, $workflowName, true, $filters);
        if ($filters) {
            $result['filter'] = $filters;
        }

        return $this->emit($io, $format, $result, function () use ($io, $result): void {
            $io->title(sprintf('%s (%s): %d', $result['class'], $result['workflow'], $result['total']));
            $io->table(['marking', 'count', 'description', 'transitions out'], array_map(fn (array $m) => [
                $m['marking'], $m['count'], $m['info'] ?? '',
                implode("\n", array_map(fn (array $t) => sprintf('%s → %s%s', $t['name'], implode('|', $t['to']), isset($t['info']) ? '  '.$t['info'] : ''), $m['transitions'])),
            ], $result['markings']));
        });
    }

    /** @return array{class: string, workflow: string, total: int, markings: list<array<string, mixed>>} */
    private function describe(string $class, string $workflowName, bool $withTransitions, array $filters = []): array
    {
        $workflow = $this->workflowHelperService->getWorkflowByCode($workflowName);
        $counts = $this->counts($class, $filters);
        $store = $workflow->getMetadataStore();

        // Every place the workflow defines, then any marking found in the data that it doesn't
        // (a renamed or removed place still holding rows is exactly what this should surface).
        $places = array_values($workflow->getDefinition()->getPlaces());
        $markings = [];
        foreach (array_unique([...$places, ...array_map('strval', array_keys($counts))]) as $place) {
            $known = in_array($place, $places, true);
            $row = array_filter([
                'marking' => $place,
                'count' => (int) ($counts[$place] ?? 0),
                'info' => $known ? ($store->getPlaceMetadata($place)['info'] ?? null) : 'not a place in this workflow',
            ], fn ($v) => null !== $v);
            if ($withTransitions) {
                $row['transitions'] = $known ? $this->transitionsFrom($workflow, $place) : [];
            }
            $markings[] = $row;
        }

        return ['class' => $class, 'workflow' => $workflowName, 'total' => array_sum(array_column($markings, 'count')), 'markings' => $markings];
    }

    /**
     * Entities per marking, from the entity table itself: the marking is the record of where
     * things are, whatever transport the messages travel on.
     *
     * @param array<string, mixed> $filters
     *
     * @return array<string, int>
     */
    private function counts(string $class, array $filters): array
    {
        $em = $this->doctrine->getManagerForClass($class) ?? $this->entityManager;
        if (!$em->getClassMetadata($class)->hasField('marking')) {
            throw new \LogicException(sprintf('%s has a workflow but no "marking" field to count by.', $class));
        }
        $metadata = $em->getClassMetadata($class);
        foreach (array_keys($filters) as $field) {
            $name = explode('__', (string) $field, 2)[0];
            if (!$metadata->hasField($name) && !$metadata->hasAssociation($name)) {
                throw new \InvalidArgumentException(sprintf(
                    'Cannot filter %s by "%s". Its fields: %s.',
                    $class, $name, implode(', ', [...$metadata->getFieldNames(), ...$metadata->getAssociationNames()]),
                ));
            }
        }
        $qb = $em->createQueryBuilder()
            ->select('e.marking AS marking, COUNT(e) AS n')
            ->from($class, 'e')
            ->groupBy('e.marking');
        QueryFilters::apply($qb, $filters);

        $counts = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $counts[(string) $row['marking']] = (int) $row['n'];
        }

        return $counts;
    }

    /** @return list<array{name: string, to: list<string>, info?: string, guard?: string}> */
    private function transitionsFrom(WorkflowInterface $workflow, string $place): array
    {
        $store = $workflow->getMetadataStore();
        $transitions = [];
        foreach ($workflow->getDefinition()->getTransitions() as $transition) {
            if (!in_array($place, $transition->getFroms(), true)) {
                continue;
            }
            $meta = $store->getTransitionMetadata($transition);
            $transitions[] = array_filter([
                'name' => $transition->getName(),
                'to' => array_values($transition->getTos()),
                'info' => $meta['info'] ?? null,
                'guard' => isset($meta['guard']) ? (string) $meta['guard'] : null,
            ], fn ($v) => null !== $v);
        }

        return $transitions;
    }

    /** @param list<string> $classes */
    private function resolveClass(string $name, array $classes): ?string
    {
        foreach ($classes as $class) {
            if ($class === ltrim($name, '\\') || 0 === strcasecmp(substr($class, (int) strrpos($class, '\\') + 1), $name)) {
                return $class;
            }
        }

        return null;
    }

    private function emit(SymfonyStyle $io, string $format, array $data, callable $text): int
    {
        if ('json' === $format) {
            $io->writeln(json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
        } else {
            $text();
        }

        return Command::SUCCESS;
    }
}
