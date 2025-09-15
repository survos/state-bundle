<?php

namespace Survos\StateBundle\Traits;

interface SurvosMarkingInterface
{
    public function getMarking(): ?string;

    public function setMarking(?string $marking, $context = []): self;
}
