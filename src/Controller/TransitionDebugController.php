<?php

declare(strict_types=1);

namespace Survos\StateBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Survos\StateBundle\Service\WorkflowHelperService;
use Survos\StateBundle\Traits\MarkingInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

class TransitionDebugController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WorkflowHelperService $workflowHelperService,
    ) {}

    #[Route('/debug/{globalKey}/{workflowCode}/{entityId}', name: 'survos_state_debug_transitions')]
    #[Route('/debug/{globalKey}/{workflowCode}/{entityId}/{transition}', name: 'survos_state_debug_apply')]
    public function debug(
        string $globalKey,
        string $workflowCode,
        int|string $entityId,
        ?string $transition = null,
        #[MapQueryParameter] ?string $redirectUrl = null,
    ): Response {
        $class = $this->workflowHelperService->classFromGlobalKey($globalKey);

        /** @var MarkingInterface $entity */
        $entity = $this->entityManager->getRepository($class)->find($entityId);
        if (!$entity) {
            throw $this->createNotFoundException("$class #$entityId not found.");
        }

        $workflow = $this->workflowHelperService->getWorkflowByCode($workflowCode);

        if ($transition !== null) {
            $success = false;
            if ($transition === '_hard_reset') {
                $entity->setMarking($workflow->getDefinition()->getInitialPlaces()[0]);
                $this->entityManager->flush();
                $this->addFlash('warning', 'Marking reset to initial place.');
                $success = true;
            } elseif ($workflow->can($entity, $transition)) {
                $workflow->apply($entity, $transition);
                $this->entityManager->flush();
                $this->addFlash('success', 'Transition "' . $transition . '" applied.');
                $success = true;
            } else {
                foreach ($workflow->buildTransitionBlockerList($entity, $transition) as $blocker) {
                    $this->addFlash('danger', $blocker->getMessage());
                }
            }

            if ($success && $redirectUrl) {
                return $this->redirect($redirectUrl);
            }

            return $this->redirectToRoute('survos_state_debug_transitions', [
                'globalKey'   => $globalKey,
                'workflowCode' => $workflowCode,
                'entityId'    => $entityId,
                'redirectUrl' => $redirectUrl,
            ]);
        }

        $enabledNames = [];
        foreach ($workflow->getEnabledTransitions($entity) as $t) {
            $enabledNames[$t->getName()] = true;
        }

        // Group transitions by name, merging from/to and collecting metadata once per name.
        $metadataStore = $workflow->getMetadataStore();
        $transitions = [];
        foreach ($workflow->getDefinition()->getTransitions() as $t) {
            $name = $t->getName();
            if (!isset($transitions[$name])) {
                $transitions[$name] = [
                    'name'    => $name,
                    'froms'   => [],
                    'tos'     => [],
                    'meta'    => $metadataStore->getTransitionMetadata($t),
                    'enabled' => isset($enabledNames[$name]),
                ];
            }
            foreach ($t->getFroms() as $from) {
                $transitions[$name]['froms'][$from] = $from;
            }
            foreach ($t->getTos() as $to) {
                $transitions[$name]['tos'][$to] = $to;
            }
        }

        $marking = $entity->getMarking();
        $placeMeta = $metadataStore->getPlaceMetadata($marking);

        return $this->render('@SurvosState/debug/transitions.html.twig', [
            'entity'      => $entity,
            'class'       => $class,
            'globalKey'   => $globalKey,
            'workflowCode' => $workflowCode,
            'entityId'    => $entityId,
            'marking'     => $marking,
            'placeMeta'   => $placeMeta,
            'transitions' => array_values($transitions),
            'redirectUrl' => $redirectUrl,
        ]);
    }
}
