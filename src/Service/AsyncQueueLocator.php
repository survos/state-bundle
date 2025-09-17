<?php
declare(strict_types=1);

namespace Survos\StateBundle\Service;

use Survos\StateBundle\Util\QueueNameUtil;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final class AsyncQueueLocator
{
    public bool $sync = false;

    /**
     * @param array<string, array<string, string>> $map  e.g. ['media' => ['download' => 'media.download']]
     */
    public function __construct(private readonly array $map) {}

    // --- Primary (workflow-aware) API ---------------------------------------

    public function isAsync(string $workflow, string $transition): bool
    {
        if ($this->sync) {
            return false;
        }
        [$wf, $tr] = QueueNameUtil::normalizePair($workflow, $transition);
        return isset($this->map[$wf][$tr]);
    }

    public function queueFor(string $workflow, string $transition): ?string
    {
        [$wf, $tr] = QueueNameUtil::normalizePair($workflow, $transition);
        return $this->map[$wf][$tr] ?? null;
    }

    /** @return TransportNamesStamp[] */
    public function stampsFor(string $workflow, string $transition): array
    {
        $q = $this->queueFor($workflow, $transition);
        return $q ? [new TransportNamesStamp([$q])] : [];
    }

    /** @return array<string, array<string, string>> */
    public function all(): array
    {
        return $this->map;
    }

    // --- Convenience / BC helpers ------------------------------------------

    /** Accept "workflow.transition" packed route */
    public function queueForRoute(string $route): ?string
    {
        if (str_contains($route, '.')) {
            [$wf, $tr] = explode('.', $route, 2);
            return $this->queueFor($wf, $tr);
        }
        // Fallback: treat as transition-only (if unique)
        return $this->queueForTransitionOnly($route);
    }

    /** Deprecated: transition-only lookup (works only if unique across workflows) */
    public function queueForTransitionOnly(string $transition): ?string
    {
        $tr = QueueNameUtil::normalizeSlug($transition);
        $found = null;
        foreach ($this->map as $trs) {
            if (isset($trs[$tr])) {
                if ($found !== null) {
                    // ambiguous across workflows
                    return null;
                }
                $found = $trs[$tr];
            }
        }
        return $found;
    }
}
