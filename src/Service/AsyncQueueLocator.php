<?php
declare(strict_types=1);

namespace Survos\StateBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Survos\StateBundle\Message\TransitionMessage;
use Survos\StateBundle\Messenger\Contract\ContextStampProviderInterface;
use Survos\StateBundle\Messenger\Stamp\ContextStamp;
use Survos\StateBundle\Util\QueueNameUtil;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Zenstruck\Messenger\Monitor\Stamp\DescriptionStamp;
use Zenstruck\Messenger\Monitor\Stamp\TagStamp;

final class AsyncQueueLocator
{
    public bool $sync = false;
    public ?string $transport=null; // force override

    /**
     * @param array<string, array<string, string>> $map  e.g. ['media' => ['download' => 'media.download']]
     */
    public function __construct(
        private readonly array $map,
        private readonly array $placeTransitions,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    // --- Primary (workflow-aware) API ---------------------------------------

    public function getPlaceTransitions(string $workflow, string $placename): array
    {
        return $this->placeTransitions[$workflow][$placename] ?? [];
    }
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
        if ($this->sync) {
            return null;
        }
        if ($this->transport) {
            return $this->transport;
        }
        [$wf, $tr] = QueueNameUtil::normalizePair($workflow, $transition);
        return $this->map[$wf][$tr] ?? null;
    }

    /** @return TransportNamesStamp[] */
    public function stampsFor(string $workflow, string $transition, ?string $id=null): array
    {
        assert(false, "use ->stamps(msg)");
    }

    public function stamps(TransitionMessage $message): array
    {
        if ($this->sync) {
            $stamps = [new TransportNamesStamp(['sync'])];
        } else {
            $q = $this->queueFor($message->workflow, $message->transitionName);
            $stamps =  $q ? [new TransportNamesStamp([$q])] : [];
        }

//        if (class_exists(DescriptionStamp::class)) {
//            $stamps[] = new DescriptionStamp($message->transitionName);
//        }
//        if ($message->id && class_exists(DescriptionStamp::class)) {
//            $shortClass = new \ReflectionClass($message->className);
//            $stamps[] = new DescriptionStamp("{$shortClass}:{$message->id} {$message->transitionName}");
//        }

        if (is_a($className=$message->className, ContextStampProviderInterface::class, true)) {
            if ($entity = $this->entityManager->find($className, $message->id)) {
                $contextStamp = $entity->getContextStamp();
            }
            if (is_array($contextStamp)) {
                foreach ($contextStamp as $key => $val) {
                    $stamps[] = new ContextStamp($val, (string)$key);
                }
            } else {
                $stamps[] = new ContextStamp($contextStamp);
            }
        }
        return $stamps;
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
