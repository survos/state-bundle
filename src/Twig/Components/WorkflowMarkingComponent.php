<?php

declare(strict_types=1);

namespace Survos\StateBundle\Twig\Components;

use Doctrine\Persistence\ManagerRegistry;
use Survos\StateBundle\Service\AsyncQueueLocator;
use Survos\StateBundle\Traits\MarkingInterface;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\TransitionBlocker;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'state:workflow-marking',
    template: '@SurvosState/components/workflow_marking.html.twig',
)]
final class WorkflowMarkingComponent
{
    public MarkingInterface $subject;

    public string $size = 'sm';

    public bool $showMarking = true;

    public bool $showTransitions = true;

    /** inline: badge + available buttons. table: full transition table with blocked rows. */
    public string $layout = 'inline';

    /** Set to enable inline Apply buttons (uses the debug apply route). */
    public ?string $globalKey = null;

    public ?string $workflowCode = null;

    /** URL to redirect to after a successful transition. Defaults to current page if omitted. */
    public ?string $redirectUrl = null;

    /** Show transitions the marking blocks, dimmed, with the reason on hover. */
    public bool $showBlocked = true;

    public function __construct(
        private readonly Registry $workflowRegistry,
        private readonly AsyncQueueLocator $asyncQueueLocator,
        private readonly ManagerRegistry $managerRegistry,
    ) {
    }

    /**
     * The Doctrine identifier, NOT $subject->erp->entityId.
     *
     * The apply route resolves this with a repository find(), so it has to be the
     * primary key. Those coincide for an entity keyed by a ULID, which is why this
     * went unnoticed, but a route identity can be anything -- ssai's Intake resolves
     * to "{tenant}/{code}", and a value with a slash in it cannot even be generated
     * into the {entityId} route segment.
     */
    public function getEntityId(): int|string
    {
        $class = $this->subject::class;
        $em    = $this->managerRegistry->getManagerForClass($class);
        if ($em === null) {
            throw new \LogicException(sprintf('%s is not managed by any entity manager.', $class));
        }

        $ids = $em->getClassMetadata($class)->getIdentifierValues($this->subject);
        if (count($ids) !== 1) {
            throw new \LogicException(sprintf(
                '%s has a composite identifier (%s); the transition apply route addresses entities by a single id.',
                $class,
                implode(', ', array_keys($ids)) ?: 'none',
            ));
        }

        return reset($ids);
    }

    public function getMarking(): ?string
    {
        return $this->subject->getMarking();
    }

    public function getPlaceMeta(): array
    {
        $marking = $this->subject->getMarking();
        if ($marking === null) {
            return [];
        }
        return $this->workflowRegistry->get($this->subject)
            ->getMetadataStore()
            ->getPlaceMetadata($marking);
    }

    /**
     * One row per transition NAME (a name can appear on several Transition objects, one
     * per `from` place -- they are the same button to an operator).
     *
     * `enabled` alone was never enough to build a usable UI: it says a button should be
     * hidden, not why, so the operator's only way to learn the reason was to click and
     * read a flash. buildTransitionBlockerList() is where the guards actually put their
     * reasoning ("Image can close only after pixelsDone=true"), so it is collected here
     * and rendered as the tooltip on the dimmed button.
     *
     * @return list<array{name: string, label: string, description: string|null, guard: string|null, enabled: bool, async: bool, blockers: list<string>}>
     */
    public function getTransitions(): array
    {
        $workflow = $this->workflowRegistry->get($this->subject);
        $store    = $workflow->getDefinition()->getMetadataStore();
        $flowName = $workflow->getName();

        $enabledMap = [];
        foreach ($workflow->getEnabledTransitions($this->subject) as $t) {
            $enabledMap[$t->getName()] = true;
        }

        // A transition name can appear on several Transition objects, one per `from`
        // place. Collected first because the froms are what makes a marking-blocked
        // transition explainable.
        $fromsByName = [];
        foreach ($workflow->getDefinition()->getTransitions() as $transition) {
            foreach ($transition->getFroms() as $from) {
                $fromsByName[$transition->getName()][$from] = $from;
            }
        }

        $seen        = [];
        $transitions = [];
        foreach ($workflow->getDefinition()->getTransitions() as $transition) {
            $name = $transition->getName();
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;

            $meta    = $store->getTransitionMetadata($transition);
            $enabled = isset($enabledMap[$name]);

            $blockers = [];
            if (!$enabled) {
                // Only for blocked transitions: this re-fires the guard listeners, so
                // running it on transitions we already know are enabled would double the
                // guard work on every page render for no added information.
                foreach ($workflow->buildTransitionBlockerList($this->subject, $name) as $blocker) {
                    // Symfony's wording for the marking case ("The marking does not enable
                    // the transition") restates the fact you can already see -- the button
                    // is dimmed. Naming the places it IS available from answers the question
                    // actually being asked, which is what has to happen first.
                    $blockers[] = $blocker->getCode() === TransitionBlocker::BLOCKED_BY_MARKING
                        ? sprintf('Available from: %s', implode(', ', $fromsByName[$name] ?? []))
                        : $blocker->getMessage();
                }
            }

            $transitions[] = [
                'name'        => $name,
                'label'       => is_string($meta['label'] ?? null) ? $meta['label'] : self::humanize($name),
                'description' => $meta['description'] ?? null,
                'guard'       => $meta['guard'] ?? null,
                'enabled'     => $enabled,
                // Asked of the locator rather than read off the metadata: the locator is
                // what actually decides routing at dispatch time (it also honours the
                // global sync override), so this is the same answer the bus will give.
                'async'       => $this->asyncQueueLocator->isAsync($flowName, $name),
                'blockers'    => $blockers,
            ];
        }

        return $transitions;
    }

    /** finish_capture -> "Finish capture". Only a fallback; an explicit label metadata wins. */
    private static function humanize(string $name): string
    {
        return ucfirst(str_replace('_', ' ', $name));
    }

    public function getWorkflowName(): string
    {
        return $this->workflowRegistry->get($this->subject)->getName();
    }
}
