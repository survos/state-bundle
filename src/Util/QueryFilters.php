<?php
declare(strict_types=1);

namespace Survos\StateBundle\Util;

use Doctrine\ORM\QueryBuilder;

/**
 * The --filter syntax shared by state:iterate and state:stats: URL query-string style
 * ("project=1&lang=en"), with `field__isnull=1`, `null` / `!null` values, %wildcards% for LIKE,
 * and arrays (`marking[]=new&marking[]=active`) for IN.
 */
final class QueryFilters
{
    /** @return array<string, mixed> */
    public static function parse(?string $filterString): array
    {
        if (!$filterString) {
            return [];
        }
        parse_str($filterString, $filters);

        return $filters;
    }

    /** @param array<string, mixed> $filters */
    public static function apply(QueryBuilder $qb, array $filters, string $alias = 'e'): void
    {
        foreach ($filters as $field => $value) {
            $operator = null;
            if (str_contains((string) $field, '__')) {
                [$field, $operator] = explode('__', (string) $field, 2);
            }

            $parameter = str_replace('.', '_', (string) $field);
            if ($operator === 'isnull') {
                $isNull = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                $qb->andWhere(sprintf($alias.'.%s IS %sNULL', $field, $isNull === false ? 'NOT ' : ''));
                continue;
            }

            if (is_string($value) && strtolower($value) === 'null') {
                $qb->andWhere(sprintf($alias.'.%s IS NULL', $field));
                continue;
            }

            if (is_string($value) && strtolower($value) === '!null') {
                $qb->andWhere(sprintf($alias.'.%s IS NOT NULL', $field));
                continue;
            }

            // A %-delimited value means LIKE. --filter is documented as urlQuerystring style
            // and this was always the intent: a second private applyFilters() implemented
            // exactly this and was never called -- its only call site sat commented out -- so a
            // value like `originalUrl=%clevelandart%` compiled to an exact `=` against the
            // literal string "%clevelandart%" and matched nothing. That dead copy has been
            // deleted; this is now the one and only filter applier.
            //
            // The silent part is what made it expensive: iterate then reports
            // "No items found for filter", which reads as "your data is wrong" rather than
            // "your operator was ignored" -- against a dataset that demonstrably had 24 matching
            // rows. Keep the wildcard branch ahead of the exact-match fallback so the documented
            // syntax and the executed query cannot drift apart again.
            if (is_string($value) && (str_starts_with($value, '%') || str_ends_with($value, '%'))) {
                $qb->andWhere(sprintf($alias.'.%s LIKE :%s', $field, $parameter));
                $qb->setParameter($parameter, $value);
                continue;
            }

            if (is_array($value)) {
                $qb->andWhere(sprintf($alias.'.%s IN (:%s)', $field, $parameter));
            } else {
                $qb->andWhere(sprintf($alias.'.%s = :%s', $field, $parameter));
            }
            $qb->setParameter($parameter, $value);
        }
        }
}
