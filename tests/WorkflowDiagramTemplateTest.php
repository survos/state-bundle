<?php

declare(strict_types=1);

namespace Survos\StateBundle\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Metadata\InMemoryMetadataStore;
use Symfony\Component\Workflow\Transition;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class WorkflowDiagramTemplateTest extends TestCase
{
    public function testSidebarDisplaysTheActualEscapedGuard(): void
    {
        $transition = new Transition('check', 'new', 'done');
        $guards = new \SplObjectStorage();
        $guards[$transition] = ['async' => true, 'description' => 'Download <metadata>', 'guard' => "subject.type == 'symfony-bundle' and subject.version < 9"];
        $metadata = new InMemoryMetadataStore([], [], $guards);
        $definition = new Definition(['new', 'done'], [$transition], 'new', $metadata);
        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['base.html.twig' => '{% block stylesheets %}{% endblock %}{% block body %}{% endblock %}']),
            new FilesystemLoader(__DIR__.'/../templates'),
        ]), ['strict_variables' => true]);
        foreach (['stimulus_controller', 'stimulus_target', 'stimulus_action', 'survos_stimulus', 'workflow_digraph', 'path'] as $name) {
            $twig->addFunction(new TwigFunction($name, static fn (...$args) => ''));
        }
        $twig->addFunction(new TwigFunction('survos_workflow_metadata', static fn ($flow, $key, $subject) => $metadata->getMetadata($key, $subject)));
        $html = $twig->render('d3-workflow.html.twig', [
            'flowCode' => 'test', 'definition' => $definition, 'digraph' => 'digraph{}',
            'app' => (object) ['request' => (object) ['query' => new ParameterBag()]],
        ]);
        self::assertStringContainsString('id="wf-transitions-panel"', $html);
        self::assertStringContainsString('Download &lt;metadata&gt;', $html);
        self::assertStringContainsString('<span class="wf-tag">Async</span>', $html);
        self::assertStringContainsString('<div class="wf-guard">', $html);
        self::assertStringContainsString('subject.type == &#039;symfony-bundle&#039; and subject.version &lt; 9', $html);
    }
}
