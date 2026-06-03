<?php

declare(strict_types=1);

namespace Survos\StateBundle\Menu;

use Doctrine\Persistence\ManagerRegistry;
use Survos\FieldBundle\Registry\EntityMetaRegistry;
use Survos\StateBundle\Service\WorkflowHelperService;
use Survos\TablerBundle\Event\MenuEvent;
use Survos\TablerBundle\Menu\AbstractAdminMenuSubscriber;
use Survos\TablerBundle\Service\IconService;
use Survos\TablerBundle\Service\RouteAliasService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Routing\RouterInterface;

/**
 * Auto-registers a "Workflows" dropdown in the Tabler admin navbar, listing every
 * registered state machine. Each entry links to its live diagram (survos_workflow);
 * a "Summary" entry links to the marking-stats dashboard.
 *
 * This is the piece other Survos bundles ship (search, meili, claims, ai-workflow …)
 * and that state-bundle was missing, which is why workflows never showed up in the
 * admin navbar. Only wired when tabler-bundle is installed (see SurvosStateBundle).
 * Items self-skip when their route is absent (add() checks route existence), so this
 * is safe even if dynamic routing is disabled.
 */
final class StateMenuSubscriber extends AbstractAdminMenuSubscriber
{
    public function __construct(
        private readonly WorkflowHelperService $workflowHelper,
        ?RouterInterface $router = null,
        ?RouteAliasService $routeAliasService = null,
        ?IconService $iconService = null,
        ?ManagerRegistry $managerRegistry = null,
        ?EntityMetaRegistry $entityMetaRegistry = null,
    ) {
        parent::__construct($router, $routeAliasService, $iconService, $managerRegistry, $entityMetaRegistry);
    }

    protected function getLabel(): string
    {
        return 'Workflows';
    }

    /** We build the menu by workflow name, not by ApiResource entity. */
    protected function getResourceClasses(): array
    {
        return [];
    }

    protected function getGroupIcon(): ?string
    {
        return 'sitemap';
    }

    #[AsEventListener(event: MenuEvent::ADMIN_NAVBAR_MENU)]
    public function onAdminNavbarMenu(MenuEvent $event): void
    {
        $names = array_keys($this->workflowHelper->getWorkflowsIndexedByName());
        if (!$names) {
            return;
        }
        sort($names);

        $submenu = $this->addSubmenu($event->getMenu(), $this->getLabel(), $this->getGroupIcon());

        // Marking-stats dashboard (counts per place, per entity).
        $this->add($submenu, 'survos_state_workflow_dashboard', label: 'Summary', icon: 'chart-pie');

        foreach ($names as $name) {
            // Live, always-current diagram for this state machine.
            $this->add($submenu, 'survos_workflow', ['flowCode' => $name], label: $name);
        }
    }
}
