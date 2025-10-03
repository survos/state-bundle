<?php
declare(strict_types=1);

namespace Survos\StateBundle\Command;

use Doctrine\DBAL\Connection;
use Survos\StateBundle\Messenger\Stamp\ContextStamp;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\StampInterface;
use ReflectionClass;
use ReflectionProperty;

#[AsCommand(name: 'state:stats', description: 'Show messenger stats with ContextStamp breakdown (Doctrine transport; deserializes Envelope from body with de-escaping).')]
final class StateStatsCommand
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Messenger table name')]
        string $table = 'messenger_messages',
        #[Option('Queue name to inspect (default: all)')]
        ?string $queue = null,
        #[Option('Env var NAME to read filter values from (overrides ENV)')]
        ?string $filterEnv = null,
        #[Option('Pretty-print the first pending message as JSON (decoded from body)')]
        bool $debugBody = false,
    ): int {
        $io->title('State / Messenger Context Stats');

        // precedence: --filter-env > STATE_FILTER_ENV > "CONTEXT_STAMP"
        $envName = $filterEnv ?: (\getenv('STATE_FILTER_ENV') ?: 'CONTEXT_STAMP');
        $filterValues = $this->readFilterValues($envName);

        $params = [];
        $where = 'delivered_at IS NULL';
        if ($queue) {
            $where .= ' AND queue_name = :q';
            $params['q'] = $queue;
        }

        $tableSql = $this->guardIdentifier($table);
        $sql = sprintf('SELECT id, queue_name, body FROM %s WHERE %s ORDER BY available_at ASC', $tableSql, $where);
        $rows = $this->db->fetchAllAssociative($sql, $params);

        // Pretty JSON dump of the first Envelope, if requested
        if ($debugBody && isset($rows[0])) {
            $io->section('First pending message (pretty JSON from PHP-serialized Envelope in body)');
            $env = $this->decodeEnvelopeFromBody((string)$rows[0]['body'], /*allowAllClasses*/ true);
            $out = [
                'id'       => $rows[0]['id'] ?? null,
                'queue'    => $rows[0]['queue_name'] ?? null,
                'envelope' => $env instanceof Envelope ? [
                    'message_class' => get_class($env->getMessage()),
                    'message'       => $this->normalizeValue($env->getMessage(), 0, 3, 100),
                    'stamps'        => array_map(
                        fn(array $arr) => array_map(fn($s) => $this->normalizeValue($s, 0, 2, 50), $arr),
                        $this->extractAllStampsMap($env)
                    ),
                ] : 'unserialize_failed',
            ];
            $io->writeln(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $totalsByQueue = [];
        $contextsByQueue = [];       // [queue][contextValue] => count
        $filteredTotalsByQueue = []; // [queue] => count
        $queues = [];

        foreach ($rows as $row) {
            $qn = (string) $row['queue_name'];
            $queues[$qn] = true;
            $totalsByQueue[$qn] = ($totalsByQueue[$qn] ?? 0) + 1;

            $contextValue = $this->extractContextStampFromBody((string)$row['body']);
            if ($contextValue !== null) {
                $contextsByQueue[$qn][$contextValue] = ($contextsByQueue[$qn][$contextValue] ?? 0) + 1;
            }

            if ($filterValues !== [] && $contextValue !== null && in_array((string)$contextValue, $filterValues, true)) {
                $filteredTotalsByQueue[$qn] = ($filteredTotalsByQueue[$qn] ?? 0) + 1;
            }
        }

        // Totals
        $io->section('Totals by queue (pending, not delivered)');
        $this->renderTable($io, ['Queue', 'Pending'], $totalsByQueue);

        // Per-context breakdown
        $io->section('Context breakdown by queue (ContextStamp.value)');
        if ($contextsByQueue === []) {
            $io->writeln('No ContextStamp found on pending messages.');
        } else {
            foreach ($contextsByQueue as $qn => $ctx) {
                ksort($ctx);
                $io->writeln("<info>$qn</info>");
                $this->renderTable($io, ['Context', 'Count'], $ctx);
            }
        }

        // Filtered totals (if active)
        if ($filterValues !== []) {
            $io->section(sprintf('Filtered totals (env %s = %s)', $envName, implode(',', $filterValues)));
            if ($filteredTotalsByQueue === []) {
                $io->writeln('No pending messages match the filter.');
            } else {
                $this->renderTable($io, ['Queue', 'Filtered Pending'], $filteredTotalsByQueue);
            }
        }

        // Helper commands to consume ONLY the first filter value (defaults to "glam")
        $exampleValue = $filterValues[0] ?? 'glam';
        if ($queues !== []) {
            ksort($queues);
            $io->section(sprintf('How to consume ONLY "%s" per queue (using env %s)', $exampleValue, $envName));
            foreach (array_keys($queues) as $qn) {
                $io->writeln(sprintf(
                    '<comment>%s</comment>',
                    $this->consumeCommandExample($envName, $exampleValue, $qn)
                ));
            }
        }

        return 0;
    }

    private function consumeCommandExample(string $envName, string $value, string $queue): string
    {
        // Keep it consistent with your worker usage
        return sprintf('%s=%s bin/console messenger:consume %s -vv --sleep=200000', $envName, $value, $queue);
    }

    private function renderTable(SymfonyStyle $io, array $headers, array $assoc): void
    {
        ksort($assoc);
        $rows = [];
        foreach ($assoc as $k => $v) {
            $rows[] = [(string)$k, (string)$v];
        }
        $io->table($headers, $rows);
    }

    /** Conservative identifier guard (letters/numbers/_/.) */
    private function guardIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_\.]+$/', $identifier)) {
            throw new \InvalidArgumentException("Invalid identifier: {$identifier}");
        }
        return $identifier;
    }

    /**
     * Decode Doctrine transport "body" into an Envelope:
     * - Some SQL clients escape as JSON string literal → json_decode once
     * - Undo C-style escapes (\" \\ \0) via stripcslashes
     * - Unserialize with controlled allowed_classes
     *
     * $allowAllClasses=true only for --debug-body pretty dump.
     */
    private function decodeEnvelopeFromBody(string $body, bool $allowAllClasses): ?Envelope
    {
        $serialized = $body;

        // If it's a JSON/quoted string literal, decode or strip once
        $trim = ltrim($serialized);
        if ($trim !== '' && ($trim[0] === '"' || $trim[0] === "'") && substr($serialized, -1) === $trim[0]) {
            $decoded = json_decode($serialized, true);
            if (is_string($decoded)) {
                $serialized = $decoded;
            } else {
                $serialized = substr($serialized, 1, -1);
            }
        }

        // Undo C-style escapes to restore a valid PHP-serialized string
        $serialized = stripcslashes($serialized);

        $opts = ['allowed_classes' => $allowAllClasses ? true : [Envelope::class, ContextStamp::class]];
        $env = @unserialize($serialized, $opts);

        return $env instanceof Envelope ? $env : null;
    }

    /**
     * Extract ContextStamp.value by decoding the Envelope with a minimal allow-list.
     */
    private function extractContextStampFromBody(string $body): string|int|null
    {
        $env = $this->decodeEnvelopeFromBody($body, /*allowAllClasses*/ false);
        if (!$env instanceof Envelope) {
            return null;
        }
        $stamps = $env->all(ContextStamp::class);
        if ($stamps === []) {
            return null;
        }
        /** @var ContextStamp $last */
        $last = end($stamps);
        return $last->value;
    }

    /**
     * Reflect private "stamps" property to get full map: [FQCN => StampInterface[]].
     */
    private function extractAllStampsMap(Envelope $env): array
    {
        $rc = new ReflectionClass(Envelope::class);
        $prop = $rc->getProperty('stamps');
        $prop->setAccessible(true);
        /** @var array<class-string, array<int, StampInterface>> $map */
        $map = $prop->getValue($env) ?? [];
        ksort($map);
        return $map;
    }

    /**
     * Normalize arbitrary values/objects to arrays suitable for JSON.
     * Depth-limited to avoid runaway recursion.
     */
    private function normalizeValue(mixed $value, int $depth, int $maxDepth, int $maxItems): mixed
    {
        if ($depth >= $maxDepth) {
            return is_object($value) ? ('object('.get_class($value).')') : (is_array($value) ? 'array(...)' : $value);
        }

        if (is_array($value)) {
            $out = [];
            $i = 0;
            foreach ($value as $k => $v) {
                if ($i++ >= $maxItems) { $out['…'] = 'truncated'; break; }
                $out[$k] = $this->normalizeValue($v, $depth+1, $maxDepth, $maxItems);
            }
            return $out;
        }

        if (is_object($value)) {
            if ($value instanceof \Stringable) {
                return (string)$value;
            }
            $rc = new ReflectionClass($value);
            $props = [];
            foreach ($rc->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED | ReflectionProperty::IS_PRIVATE) as $p) {
                $p->setAccessible(true);
                try {
                    $props[$p->getName()] = $this->normalizeValue($p->getValue($value), $depth+1, $maxDepth, $maxItems);
                } catch (\Throwable $e) {
                    $props[$p->getName()] = 'unreadable('.$e->getMessage().')';
                }
            }
            return ['_class' => $rc->getName(), '_props' => $props];
        }

        return $value; // scalar/null
    }

    /** Reads and normalizes comma-separated filter values from ENV var NAME. */
    private function readFilterValues(string $envName): array
    {
        $raw = \getenv($envName);
        if ($raw === false || $raw === '') {
            return [];
        }
        $parts = array_map('trim', explode(',', $raw));
        $parts = array_filter($parts, static fn($v) => $v !== '');
        return array_map('strval', array_values($parts));
    }
}
