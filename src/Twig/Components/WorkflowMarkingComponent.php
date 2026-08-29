<?php

declare(strict_types=1);

namespace Survos\StateBundle\Twig\Components;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Survos\StateBundle\Service\AsyncQueueLocator;
use Survos\StateBundle\Traits\MarkingInterface;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\TransitionBlocker;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

use function Symfony\Component\String\u;

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

    /** Show the whole place sequence with the current one marked -- "where am I". */
    public bool $showSteps = false;

    /**
     * Offer the force-place control. Only ever shown when the bundle also permits it
     * (survos_state.allow_force_place, debug-only by default) -- this prop can hide it
     * on a screen where it would be a foot-gun, never reveal it where the bundle said no.
     */
    public bool $showForcePlace = true;

    public function __construct(
        private readonly Registry $workflowRegistry,
        private readonly AsyncQueueLocator $asyncQueueLocator,
        private readonly ManagerRegistry $managerRegistry,
        #[Autowire('%survos_state.allow_force_place%')] private readonly bool $allowForcePlace = false,
    ) {
    }

    /**
     * Whether any workflow in this app supports the subject.
     *
     * Not a formality. A bundle template can render this component for an entity the
     * bundle owns while the WORKFLOW for that entity lives in one particular app --
     * dataset-bundle ships DatasetInfo and its show screen, but only harvest defines a
     * DatasetWorkflow for it. Everywhere else Registry::get() would throw, so the
     * component renders nothing there instead of taking the page down.
     */
    public function hasWorkflow(): bool
    {
        return $this->workflowRegistry->has($this->subject, $this->workflowCode);
    }

    /**
     * globalKey is derivable from the subject, so passing it is optional.
     *
     * It is the FQCN underscored and upper-cased, and getting it wrong (or forgetting
     * it) silently produced the read-only widget with no hint as to why -- the single
     * most common way to be confused by this component. Deriving it removes the guess;
     * an explicit value still wins, for the case where the entity is proxied or the
     * caller knows better.
     */
    public function getResolvedGlobalKey(): string
    {
        if (is_string($this->globalKey) && $this->globalKey !== '') {
            return $this->globalKey;
        }

        $class = $this->managerRegistry->getManagerForClass($this->subject::class)
            ?->getClassMetadata($this->subject::class)?->getName()
            ?? $this->subject::class;

        // Deliberately the SAME expression WorkflowHelperService::classFromGlobalKey()
        // uses to compute its candidates. The two have to agree exactly, or this derives
        // a key the apply route cannot resolve back to a class. A hand-rolled
        // underscore-before-each-capital regex does NOT agree -- it turns
        // Survos\DatasetBundle\Entity\DatasetInfo into
        // SURVOS__DATASET_BUNDLE__ENTITY__DATASET_INFO, doubling the separator the
        // namespace boundary already supplied.
        return u(ltrim($class, '\\'))->replace('\\', '_')->snake()->upper()->toString();
    }

    /** Both the app-wide switch and this instance's prop have to agree. */
    public function isForcePlaceEnabled(): bool
    {
        return $this->allowForcePlace && $this->showForcePlace;
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

    /**
     * Every place in definition order, with the current one flagged.
     *
     * A marking badge says where you are; it does not say where that is in the run of
     * the thing, which is the question during a demo or a long session. The strip is
     * the answer, and it is worth the extra render because it is the one part of this
     * component you read without meaning to.
     *
     * `flowsToNext` is what keeps it honest: a workflow's place list is a declaration
     * order, not a path, so chaining every place with an arrow claims progressions that
     * do not exist -- ssai's ImageWorkflow would read "closed -> remove -> ignored",
     * three unrelated terminal states drawn as a pipeline. The arrow is only drawn when
     * some transition actually connects a place to the one printed after it.
     *
     * @return list<array{name: string, label: string, info: string|null, bgColor: string, current: bool, flowsToNext: bool}>
     */
    public function getPlaces(): array
    {
        $definition = $this->workflowRegistry->get($this->subject)->getDefinition();
        $store      = $definition->getMetadataStore();
        $marking    = $this->subject->getMarking();

        $edges = [];
        foreach ($definition->getTransitions() as $transition) {
            foreach ($transition->getFroms() as $from) {
                foreach ($transition->getTos() as $to) {
                    $edges[$from . "\0" . $to] = true;
                }
            }
        }

        $places = array_values($definition->getPlaces());
        $rows   = [];
        foreach ($places as $i => $place) {
            $meta = $store->getPlaceMetadata($place);
            $next = $places[$i + 1] ?? null;

            $rows[] = [
                'name'        => $place,
                'label'       => is_string($meta['label'] ?? null) ? $meta['label'] : $place,
                'info'        => $meta['info'] ?? $meta['description'] ?? null,
                'bgColor'     => is_string($meta['bgColor'] ?? null) ? $meta['bgColor'] : 'secondary',
                'current'     => $place === $marking,
                'flowsToNext' => $next !== null && isset($edges[$place . "\0" . $next]),
            ];
        }

        return $rows;
    }

    public function getWorkflowName(): string
    {
        return $this->workflowRegistry->get($this->subject)->getName();
    }
}
