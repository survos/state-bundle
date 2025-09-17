<?php
declare(strict_types=1);

namespace Survos\StateBundle\Util;

final class QueueNameUtil
{
    /**
     * Normalize any identifier (workflow or transition) to a dotted slug.
     * - strips trailing "Workflow" / "Flow"
     * - lowercases
     * - collapses non-alnum to single dots
     */
    public static function normalizeSlug(string $s): string
    {
        $s = preg_replace('/(workflow|flow)$/i', '', $s) ?? $s;
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/i', '.', $s) ?? $s;
        return trim($s, '.');
    }

    /**
     * Normalize a prefix (only used for non-Doctrine DSNs). Returns '' or 'myapp.'.
     */
    public static function normalizePrefix(string $raw): string
    {
        $raw = trim($raw);
        return $raw !== '' ? rtrim($raw, '.') . '.' : '';
    }

    public static function isDoctrineDsn(string $dsn): bool
    {
        return str_starts_with($dsn, 'doctrine://');
    }

    /** @return array{0:string,1:string} */
    public static function normalizePair(string $workflow, string $transition): array
    {
        return [self::normalizeSlug($workflow), self::normalizeSlug($transition)];
    }
}
