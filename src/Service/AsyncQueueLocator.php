<?php
declare(strict_types=1);

namespace Survos\StateBundle\Service;

use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final class AsyncQueueLocator
{
    // override this to force the 'sync' transport and skip queuing, e.g. iterate Pokemon --sync
    public bool $sync=false;

    /** @param array<string,string> $transitionToQueueMap */
    public function __construct(private readonly array $transitionToQueueMap) {
    }

    public function isAsync(string $transition): bool
    {
        // OR we could send the sync stamp...
        if ($this->sync) {
            return false;
        }
        return isset($this->transitionToQueueMap[$transition]);
    }

    public function queueFor(string $transition): ?string
    {
        return $this->transitionToQueueMap[$transition] ?? null;
    }

    /** @return TransportNamesStamp[] */
    public function stampsFor(string $transition): array
    {
        $q = $this->queueFor($transition);
        return $q ? [new TransportNamesStamp([$q])] : [];
    }

    /** @return array<string,string> */
    public function all(): array
    {
        return $this->transitionToQueueMap;
    }
}
