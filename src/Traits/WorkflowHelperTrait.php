<?php

declare(strict_types=1);

namespace Survos\StateBundle\Traits;

use Doctrine\ORM\EntityManagerInterface;
use Survos\StateBundle\Service\WorkflowHelperService;
use Survos\StateBundle\Traits\MarkingInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Workflow\WorkflowInterface;

trait WorkflowHelperTrait
{
    public function _transition(
        Request                $request,
        MarkingInterface       $entity,
        WorkflowHelperService  $workflowHelperService,
        WorkflowInterface      $stateMachine,
        EntityManagerInterface $entityManager,
        ?string                $transition = null,
        string                 $_format = 'json',
    ): JsonResponse {
        if ($transition === '_hard_reset') {
            $entity->setMarking($stateMachine->getDefinition()->getInitialPlaces()[0]);
        } else {
            $stateMachine->apply($entity, $transition);
        }
        $entityManager->flush();

        return $this->json($entity);
    }
}
