<?php

declare(strict_types=1);

namespace Survos\StateBundle\Tests\Fixtures\Workflow;

final class TestSubject
{
    public function __construct(
        public string $marking = 'new',
    ) {
    }
}
