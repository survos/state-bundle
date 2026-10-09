<?php

declare(strict_types=1);

namespace Survos\StateBundle\Tests;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Survos\StateBundle\Compiler\StatePrependExtension;
use Survos\StateBundle\Service\AsyncQueueLocator;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class StatePrependExtensionTest extends TestCase
{
    public function testPlaceKickoffIsScopedToWorkflowAndSupportsNamedPlaceLists(): void
    {
        $builder = new ContainerBuilder();
        $builder->setParameter('kernel.project_dir', __DIR__);
        $builder->prependExtensionConfig('survos_state', ['workflow_paths' => [__DIR__.'/Fixtures/Workflow']]);
        $builder->prependExtensionConfig('framework', ['workflows' => ['workflows' => [
            'article' => ['places' => ['draft' => ['metadata' => ['next' => ['publish']]]]],
            'image' => ['places' => [['name' => 'draft', 'metadata' => ['next' => ['resize']]]]],
        ]]]);
        $instanceof = [];
        $configurator = new ContainerConfigurator($builder, new PhpFileLoader($builder, new FileLocator(__DIR__)), $instanceof, __DIR__, __FILE__);

        StatePrependExtension::prepend($configurator, $builder);
        $locator = new AsyncQueueLocator([], $builder->getParameter('survos_state.place_transitions'), $this->createStub(EntityManagerInterface::class));

        self::assertSame(['publish'], $locator->getPlaceTransitions('article', 'draft'));
        self::assertSame(['resize'], $locator->getPlaceTransitions('image', 'draft'));
        self::assertSame([], $locator->getPlaceTransitions('unknown', 'draft'));
    }
}
