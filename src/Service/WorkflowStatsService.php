<?php
declare(strict_types=1);

namespace Survos\StateBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Survos\StateBundle\Traits\MarkingInterface;

/**
 * Builds aggregate stats for workflow-enabled entities.
 */
final class WorkflowStatsService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EntityInterfaceDetector $entityInterfaceDetector,
    ) {}

    /**
     * @return array{
     *   entities: array<string, array{total:int, by_marking: array<string,int>}>,
     *   grand_total: int
     * }
     */
    public function buildAll(): array
    {
        $entities = [];
        $grandTotal = 0;

        foreach ($this->entityInterfaceDetector->getEntitiesImplementing(MarkingInterface::class) as $class) {
            $repo = $this->em->getRepository($class);

            // SELECT e.marking, COUNT(e) FROM Class e GROUP BY e.marking
            $qb = $repo->createQueryBuilder('e')
                ->select('e.marking AS marking, COUNT(e) AS c')
                ->groupBy('e.marking');

            $rows = $qb->getQuery()->getArrayResult();

            $byMarking = [];
            $total = 0;
            foreach ($rows as $r) {
                $m = (string)($r['marking'] ?? '');
                $c = (int)$r['c'];
                $byMarking[$m] = $c;
                $total += $c;
            }

            ksort($byMarking);
            $entities[$class] = [
                'total' => $total,
                'by_marking' => $byMarking,
            ];
            $grandTotal += $total;
        }

        return [
            'entities' => $entities,
            'grand_total' => $grandTotal,
        ];
    }
}
