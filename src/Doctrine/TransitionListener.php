<?php
declare(strict_types=1);

namespace Survos\StateBundle\Doctrine;

use Survos\StateBundle\Service\WorkflowHelperService;

/**
 * Minimal stub to satisfy service wiring. Expand as needed.
 */
final class TransitionListener
{
    /** @param iterable<object> $workflows */
    public function __construct(
        private readonly WorkflowHelperService $workflowHelperService,
        private readonly iterable $workflows
    ) {
    }
}
