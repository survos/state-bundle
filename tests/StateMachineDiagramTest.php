<?php

declare(strict_types=1);

namespace Survos\StateBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\StateBundle\Service\SurvosStateMachineGraphVizDumper;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;

final class StateMachineDiagramTest extends TestCase
{
    public function testTerminalAppearanceFollowsTopology(): void
    {
        $definition = new Definition(['new', 'in_progress', 'done'], [
            new Transition('start', 'new', 'in_progress'),
            new Transition('finish', 'in_progress', 'done'),
        ], 'new');
        $dot = (new SurvosStateMachineGraphVizDumper())->dump($definition, new Marking(['new' => 1]));
        self::assertMatchesRegularExpression('/place_done \\[.*peripheries="2"/', $dot);
        self::assertDoesNotMatchRegularExpression('/place_in_progress \\[.*peripheries=/', $dot);
        self::assertStringContainsString('label="In Progress"', $dot);
        self::assertStringContainsString('place_in_progress -> place_done', $dot);
        self::assertStringContainsString('color="#2563eb"', $dot);
    }
}
