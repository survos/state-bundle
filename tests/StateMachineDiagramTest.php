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
    public function testGuardExpressionIsIncludedInDiagramTooltip(): void
    {
        $transition = new Transition('check', 'new', 'done');
        $guard = "subject.type == 'symfony-bundle' and subject.hasValidSymfonyVersion";
        $metadata = new \SplObjectStorage();
        $metadata[$transition] = ['guard' => $guard];
        $definition = new Definition(['new', 'done'], [$transition], 'new',
            new \Symfony\Component\Workflow\Metadata\InMemoryMetadataStore([], [], $metadata));
        $dot = (new SurvosStateMachineGraphVizDumper())->dump($definition);
        self::assertStringContainsString('Check<BR/>', $dot);
        self::assertStringContainsString('<I>type==&#039;symfony-bundle&#039;', $dot);
        self::assertStringContainsString('<BR ALIGN="LEFT"/>&amp;&amp; hasValidSymfonyVersion', $dot);
        self::assertStringContainsString('Guard: '.addslashes($guard), $dot);
    }
    public function testCompactGuardPreservesQuotedValuesAndEscapesHtml(): void
    {
        $transition = new Transition('check', 'new', 'done');
        $guard = "not subject.disabled or subject.label == 'and subject.type <tag>' and subject.type not in ['excluded']";
        $metadata = new \SplObjectStorage();
        $metadata[$transition] = ['guard' => $guard];
        $definition = new Definition(['new', 'done'], [$transition], 'new',
            new \Symfony\Component\Workflow\Metadata\InMemoryMetadataStore([], [], $metadata));
        $dot = (new SurvosStateMachineGraphVizDumper())->dump($definition);
        self::assertStringContainsString('type not in [&#039;excluded&#039;]', $dot);
        self::assertStringContainsString('<I>!disabled<BR ALIGN="LEFT"/>|| label==&#039;and subject.type &lt;tag&gt;&#039;', $dot);
    }
    public function testGuardLabelReplacesExpressionOnlyInVisibleLabel(): void
    {
        $transition = new Transition('check', 'new', 'done');
        $metadata = new \SplObjectStorage();
        $metadata[$transition] = ['guard' => 'subject.allowed', 'guardLabel' => 'Allowed <today>'];
        $definition = new Definition(['new', 'done'], [$transition], 'new',
            new \Symfony\Component\Workflow\Metadata\InMemoryMetadataStore([], [], $metadata));
        $dot = (new SurvosStateMachineGraphVizDumper())->dump($definition);
        self::assertStringContainsString('<I>Allowed &lt;today&gt;</I>', $dot);
        self::assertStringContainsString('Guard: subject.allowed', $dot);
        self::assertStringNotContainsString('<I>allowed', $dot);
    }
}
