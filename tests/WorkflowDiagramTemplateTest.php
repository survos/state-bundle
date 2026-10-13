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
        [$html] = $this->render();
        self::assertStringContainsString('id="wf-transitions-panel"', $html);
        self::assertStringContainsString('Download &lt;metadata&gt;', $html);
        self::assertStringContainsString('<span class="wf-tag">Async</span>', $html);
        self::assertStringContainsString('<div class="wf-guard">', $html);
        self::assertStringContainsString('subject.type == &#039;symfony-bundle&#039; and subject.version &lt; 9', $html);
    }

    public function testDescriptionsReachTheHeaderSidebarAndHovercards(): void
    {
        [$html, $values] = $this->render();
        // Workflow description replaces the generic subtitle.
        self::assertStringContainsString('Fetch &amp; validate packages', $html);
        self::assertStringNotContainsString('Explore the states and the paths between them', $html);
        // Place descriptions are visible without selecting the place.
        self::assertMatchesRegularExpression('/<span class="wf-code">new<\/span>\s*<p class="wf-description">Discovered &lt;package&gt;<\/p>/', $html);
        // Hover cards: places keyed by name, transitions by definition index.
        self::assertSame(['label' => 'New', 'description' => 'Discovered <package>'], $values['details']['places']['new']);
        self::assertSame('check', $values['details']['transitions'][0]['name']);
        self::assertSame('Download <metadata>', $values['details']['transitions'][0]['description']);
        self::assertTrue($values['details']['transitions'][0]['async']);
    }

    /**
     * @return array{string, array<string, mixed>} the page and the values passed to the Stimulus controller
     */
    private function render(): array
    {
        $transition = new Transition('check', 'new', 'done');
        $transitionsMetadata = new \SplObjectStorage();
        $transitionsMetadata[$transition] = ['async' => true, 'description' => 'Download <metadata>', 'guard' => "subject.type == 'symfony-bundle' and subject.version < 9"];
        $metadata = new InMemoryMetadataStore(
            ['description' => 'Fetch & validate packages'],
            ['new' => ['description' => 'Discovered <package>']],
            $transitionsMetadata,
        );
        $definition = new Definition(['new', 'done'], [$transition], 'new', $metadata);
        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['base.html.twig' => '{% block page_subtitle %}{% endblock %}{% block stylesheets %}{% endblock %}{% block body %}{% endblock %}']),
            new FilesystemLoader(__DIR__.'/../templates'),
        ]), ['strict_variables' => true]);
        $controllerValues = [];
        $twig->addFunction(new TwigFunction('stimulus_controller', static function (string $name, array $values = []) use (&$controllerValues): string {
            $controllerValues = $values;

            return '';
        }));
        foreach (['stimulus_target', 'stimulus_action', 'survos_stimulus', 'workflow_digraph', 'path'] as $name) {
            $twig->addFunction(new TwigFunction($name, static fn (...$args) => ''));
        }
        $twig->addFunction(new TwigFunction('survos_workflow_metadata', static fn ($flow, $key, $subject = null) => $metadata->getMetadata($key, $subject)));
        $html = $twig->render('d3-workflow.html.twig', [
            'flowCode' => 'test', 'definition' => $definition, 'digraph' => 'digraph{}',
            'app' => (object) ['request' => (object) ['query' => new ParameterBag()]],
        ]);

        return [$html, $controllerValues];
    }
}
