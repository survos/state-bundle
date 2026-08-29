<?php

declare(strict_types=1);

namespace Survos\StateBundle\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Survos\StateBundle\Message\TransitionMessage;
use Survos\StateBundle\Service\AsyncQueueLocator;
use Survos\StateBundle\Service\WorkflowHelperService;
use Survos\StateBundle\Traits\MarkingInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

class TransitionDebugController extends AbstractController
{
    /** Token id for the apply form; the entity id is folded in so one page's token can't fire another's. */
    public const APPLY_CSRF_ID = 'survos_state_apply';

    /** Pseudo-transition: put the marking somewhere directly, running nothing. */
    public const FORCE_PLACE = '_force_place';

    /** Pseudo-transition: back to the workflow's initial place, running nothing. */
    public const HARD_RESET = '_hard_reset';

    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly WorkflowHelperService $workflowHelperService,
        private readonly AsyncQueueLocator $asyncQueueLocator,
        private readonly MessageBusInterface $bus,
        #[Autowire('%survos_state.allow_force_place%')] private readonly bool $allowForcePlace = false,
    ) {}

    // entityId is greedy and LAST on both routes. An entity's primary key is allowed to
    // contain a slash -- dataset-bundle's DatasetInfo is keyed by "nara/rg_105" -- and
    // with entityId in the middle of the path there is no requirement that can express
    // that without also swallowing the segment after it. Putting the transition first
    // costs nothing: transition names never contain a slash.
    #[Route('/debug/{globalKey}/{workflowCode}/{entityId}', name: 'survos_state_debug_transitions', methods: ['GET'], requirements: ['entityId' => '.+'])]
    public function debug(
        string $globalKey,
        string $workflowCode,
        int|string $entityId,
        #[MapQueryParameter] ?string $redirectUrl = null,
    ): Response {
        $entity   = $this->loadEntity($globalKey, $entityId);
        $workflow = $this->workflowHelperService->getWorkflowByCode($workflowCode);

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
                    'async'   => $this->asyncQueueLocator->isAsync($workflow->getName(), $name),
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

        return $this->render('@SurvosState/debug/transitions.html.twig', [
            'entity'      => $entity,
            'class'       => $entity::class,
            'globalKey'   => $globalKey,
            'workflowCode' => $workflowCode,
            'entityId'    => $entityId,
            'marking'     => $marking,
            'placeMeta'   => $metadataStore->getPlaceMetadata($marking),
            'transitions' => array_values($transitions),
            'redirectUrl' => $redirectUrl,
        ]);
    }

    /**
     * Applies one transition. POST only, and CSRF-checked.
     *
     * This used to be a GET route reachable from a plain <a href>, which meant any
     * link prefetcher, crawler, or accidental history replay could move an entity
     * through its workflow. The buttons are forms now.
     *
     * Three modes, chosen by the `mode` field:
     *   (default)  respect the workflow's own routing -- async transitions are queued,
     *              sync ones run inline.
     *   sync       force it to run now, through the real message handler (the
     *              AsyncQueueLocator's own sync override, so the code path a queued
     *              run would take is the code path you are debugging).
     *   reset      put the marking back to the initial place, running nothing.
     */
    #[Route('/debug/{globalKey}/{workflowCode}/t/{transition}/{entityId}', name: 'survos_state_debug_apply', methods: ['POST'], requirements: ['entityId' => '.+'], priority: 10)]
    #[IsCsrfTokenValid(self::APPLY_CSRF_ID, tokenKey: '_token')]
    public function apply(
        Request $request,
        string $globalKey,
        string $workflowCode,
        int|string $entityId,
        string $transition,
        #[MapQueryParameter] ?string $redirectUrl = null,
    ): Response {
        $entity   = $this->loadEntity($globalKey, $entityId);
        $workflow = $this->workflowHelperService->getWorkflowByCode($workflowCode);
        $mode     = (string) $request->request->get('mode', '');

        if ($transition === self::HARD_RESET || $mode === 'reset') {
            return $this->forcePlace(
                $entity,
                $workflow->getDefinition()->getInitialPlaces()[0],
                $workflow,
                $globalKey,
                $workflowCode,
                $entityId,
                $redirectUrl,
            );
        }

        if ($transition === self::FORCE_PLACE) {
            return $this->forcePlace(
                $entity,
                (string) $request->request->get('place', ''),
                $workflow,
                $globalKey,
                $workflowCode,
                $entityId,
                $redirectUrl,
            );
        }

        if (!$workflow->can($entity, $transition)) {
            foreach ($workflow->buildTransitionBlockerList($entity, $transition) as $blocker) {
                $this->addFlash('danger', $blocker->getMessage());
            }

            return $this->back($globalKey, $workflowCode, $entityId, $redirectUrl);
        }

        $forceSync = $mode === 'sync';
        $isAsync   = !$forceSync && $this->asyncQueueLocator->isAsync($workflow->getName(), $transition);

        // Everything goes through the bus, including the synchronous case -- the message
        // handler is where can()/apply()/flush() actually live, so routing a debug run to
        // the `sync` transport exercises the same code a queued run would, rather than a
        // parallel inline implementation that can drift away from it.
        $previousSync = $this->asyncQueueLocator->sync;
        $this->asyncQueueLocator->sync = !$isAsync;
        try {
            // $entityId, not $entity->getId(): MarkingInterface does not require a
            // getId(), and plenty of entities do not have one -- ssai's Intake is keyed
            // by `code`. The route parameter is by definition the identifier the entity
            // was just found by, so it is the one the handler will find it by too.
            $message = new TransitionMessage($entityId, $entity::class, $transition, $workflow->getName());
            $this->bus->dispatch($message, $this->asyncQueueLocator->stamps($message));
        } finally {
            $this->asyncQueueLocator->sync = $previousSync;
        }

        $this->addFlash('success', $isAsync
            ? sprintf('"%s" queued.', $transition)
            : sprintf('"%s" applied.', $transition));

        return $this->back($globalKey, $workflowCode, $entityId, $redirectUrl);
    }

    /**
     * Sets the marking directly. No transition fires, so no guard runs and no listener
     * sees it -- the entity simply IS somewhere else now.
     *
     * That is the whole value (re-run triage on an already-triaged image without a
     * manual UPDATE, park an entity mid-pipeline to reproduce a bug) and the whole
     * danger: side effects a transition would have performed have not happened, so the
     * marking can now disagree with the entity's actual data. Debug-only by default,
     * see the bundle's allow_force_place.
     *
     * The place is checked against the definition rather than trusted, so this cannot
     * write a marking the workflow has never heard of -- that state would be
     * unrecoverable through the UI, since every transition's `from` would miss it.
     */
    private function forcePlace(
        MarkingInterface $entity,
        string $place,
        WorkflowInterface $workflow,
        string $globalKey,
        string $workflowCode,
        int|string $entityId,
        ?string $redirectUrl,
    ): Response {
        if (!$this->allowForcePlace) {
            throw $this->createAccessDeniedException('Forcing a marking is disabled (survos_state.allow_force_place).');
        }

        $places = $workflow->getDefinition()->getPlaces();
        if (!in_array($place, $places, true)) {
            $this->addFlash('danger', sprintf(
                'Unknown place "%s". Known: %s',
                $place,
                implode(', ', $places),
            ));

            return $this->back($globalKey, $workflowCode, $entityId, $redirectUrl);
        }

        $from = $entity->getMarking();
        $entity->setMarking($place);
        $this->managerRegistry->getManagerForClass($entity::class)?->flush();

        $this->addFlash('warning', sprintf('Marking forced %s → %s. No transition ran.', $from ?? '(none)', $place));

        return $this->back($globalKey, $workflowCode, $entityId, $redirectUrl);
    }

    /**
     * Loaded from the manager that maps the class, not the default one. Entities on a
     * secondary EM are not exotic -- harvest's DatasetInfo lives on its own `dataset`
     * registry -- and against the default manager they simply come back as "not found",
     * which reads like a bad id rather than a wrong connection. WorkflowHelperService's
     * own message handler already resolves the manager this way.
     */
    private function loadEntity(string $globalKey, int|string $entityId): MarkingInterface
    {
        $class = $this->workflowHelperService->classFromGlobalKey($globalKey);

        $em = $this->managerRegistry->getManagerForClass($class);
        if ($em === null) {
            throw $this->createNotFoundException("$class is not managed by any entity manager.");
        }

        /** @var ?MarkingInterface $entity */
        $entity = $em->getRepository($class)->find($entityId);
        if (!$entity) {
            throw $this->createNotFoundException("$class #$entityId not found.");
        }

        return $entity;
    }

    private function back(string $globalKey, string $workflowCode, int|string $entityId, ?string $redirectUrl): Response
    {
        if ($redirectUrl) {
            return $this->redirect($redirectUrl);
        }

        return $this->redirectToRoute('survos_state_debug_transitions', [
            'globalKey'    => $globalKey,
            'workflowCode' => $workflowCode,
            'entityId'     => $entityId,
        ]);
    }
}
