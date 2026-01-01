<?php
declare(strict_types=1);

namespace Survos\StateBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Survos\StateBundle\Service\EntityInterfaceDetector;
use Survos\StateBundle\Service\WorkflowHelperService;
use Survos\StateBundle\Service\WorkflowStatsService;
use Survos\StateBundle\Traits\MarkingInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class WorkflowDashboardController extends AbstractController
{
    public function __construct(
        private WorkflowStatsService $workflowStatsService,
    ) {}

    #[Route('/_state/workflows', name: 'survos_state_workflow_dashboard')]
    public function stats(): Response
    {
        $data = $this->workflowStatsService->buildAll();

        // Shape for Chart.js: one pie per entity
        $charts = [];
        foreach ($data['entities'] as $class => $row) {
            $labels = array_keys($row['by_marking']);
            $values = array_values($row['by_marking']);

            $charts[] = [
                'class'  => $class,
                'total'  => $row['total'],
                'labels' => $labels,
                'values' => $values,
            ];
        }

        return $this->render('@SurvosState/workflow/dashboard.html.twig', [
            'grandTotal' => $data['grand_total'],
            'charts'     => $charts,
        ]);
    }

}
