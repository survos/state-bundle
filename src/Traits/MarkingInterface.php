<?php

namespace Survos\StateBundle\Traits;

interface MarkingInterface
{
    /** Pending steps keyed by phase (transition name). */
    public array $pendingSteps { get; set; }

    public function addPendingStep(string $step, string $phase): static;

    public function shiftPendingStep(string $phase): ?string;

    /** Count steps for a phase — EL guard example: subject.pendingCount('observe') == 0 */
    public function pendingCount(string $phase): int;

    public function getMarking(): ?string;

    public function setMarking(?string $marking, $context = []): self;

    public function setEnabledTransitions(array $enabledTransitions): self;

    public function getEnabledTransitions(): ?array;

    public function getEnabledTransitionCodes(): array;

    public static function getConstants(?string $prefix = null);

    public static function getFlowCodes(?string $prefix = null);

    public function getFlowCode(): string;
}
