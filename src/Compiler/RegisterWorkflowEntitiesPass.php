<?php
declare(strict_types=1);

namespace Survos\StateBundle\Compiler;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Finder\Finder;
use Survos\StateBundle\Traits\MarkingInterface;
/**
 * Scans Doctrine mapping directories and registers all entities that implement MarkingInterface.
 * Works at compile-time; no runtime queries, no tagging required.
 */
final class RegisterWorkflowEntitiesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('doctrine.orm.mappings')) {
            assert(false, "not seeing doctrine.orm.mappings");
            // DoctrineBundle not enabled? Nothing to do.
            return;
        }

        /** @var array<string, array{
         *   dir?: string,
         *   prefix?: string,
         *   type?: 'attribute'|'annotation'|'xml'|'yml'|'yaml',
         *   is_bundle?: bool
         * }> $mappings
         */
        $mappings = $container->getParameter('doctrine.orm.mappings');

        $projectDir = (string) $container->getParameter('kernel.project_dir');
        $classes = [];

        foreach ($mappings as $name => $cfg) {
            // We only support directory-based mappings here.
            if (!isset($cfg['dir']) || !is_dir($cfg['dir'])) {
                // If it's a bundle mapping (is_bundle=true), DoctrineBundle resolves it internally;
                // for simplicity, skip here or enhance to resolve bundle dirs if needed.
                continue;
            }

            $type   = $cfg['type']   ?? 'attribute';
            $prefix = rtrim((string)($cfg['prefix'] ?? ''), '\\');

            $finder = (new Finder())
                ->files()
                ->in($cfg['dir'])
                ->name('*.php');

            foreach ($finder as $file) {
                $fqcn = $this->guessClassFromFile($file->getRealPath(), $prefix, $cfg['dir']);
                if (!$fqcn || !class_exists($fqcn)) {
                    continue;
                }

                // Must implement MarkingInterface
                if (!is_subclass_of($fqcn, MarkingInterface::class)) {
                    continue;
                }

                // Must be a Doctrine entity for the configured mapping "type"
                if (!$this->isEntityForType($fqcn, $type, $file->getRealPath())) {
                    continue;
                }

                $classes[$fqcn] = true;
            }
        }

        $list = array_keys($classes);
        sort($list);

        dd($list);
        // Store for runtime use (e.g. inject into a provider service, or just keep a param).
        $container->setParameter('survos_state.workflow_entities', $list);

        // If you have a provider service expecting this argument, set it here:
        if ($container->hasDefinition(\Survos\StateBundle\Service\WorkflowEntityProvider::class)) {
            $def = $container->getDefinition(\Survos\StateBundle\Service\WorkflowEntityProvider::class);
            $def->setArgument('$configuredEntities', '%survos_state.workflow_entities%');
        }
    }

    /**
     * Infer FQCN from file path + Doctrine "prefix" (common in DoctrineBundle mappings).
     * Falls back to parsing the namespace/class from the file if prefix is empty.
     */
    private function guessClassFromFile(string $file, string $prefix, string $rootDir): ?string
    {
        if ($prefix !== '') {
            // Compute subpath relative to mapping root, turn path into class fragments.
            $rel = ltrim(str_replace('\\', '/', substr($file, strlen(rtrim($rootDir, DIRECTORY_SEPARATOR)) + 1)), '/');
            $base = preg_replace('/\.php$/', '', $rel);
            if ($base) {
                $fq = $prefix . '\\' . str_replace('/', '\\', $base);
                return $fq;
            }
        }

        // Fallback: parse file for namespace + class (naive but sufficient).
        $src = @file_get_contents($file);
        if ($src === false) {
            return null;
        }
        $ns = null;
        if (preg_match('/^namespace\s+([^;]+);/m', $src, $m)) {
            $ns = trim($m[1]);
        }
        if (preg_match('/^class\s+([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)/m', $src, $m)) {
            $cls = $m[1];
            return $ns ? ($ns . '\\' . $cls) : $cls;
        }
        return null;
    }

    /**
     * Check if a class is a Doctrine entity for the mapping "type".
     * - attribute/annotation: look for #[ORM\Entity] attribute (annotation goes through attributes in PHP 8+ projects)
     * - xml/yml/yaml: we can’t cheaply verify here without parsing mapping files, so we assume presence if in the mapped dir.
     */
    private function isEntityForType(string $fqcn, string $type, string $phpFile): bool
    {
        $type = strtolower($type);
        if (in_array($type, ['xml', 'yml', 'yaml'], true)) {
            // For xml/yml we already trust Doctrine's mapping dir scoping
            return true;
        }

        // attribute/annotation paths:
        $ref = new \ReflectionClass($fqcn);
        // skip abstract classes/traits/interfaces
        if (!$ref->isInstantiable()) {
            return false;
        }

        // Look for ORM\Entity attribute
        foreach ($ref->getAttributes() as $attr) {
            $name = $attr->getName();
            if ($name === ORM\Entity::class || $name === \Doctrine\ORM\Mapping\Entity::class) {
                return true;
            }
        }

        // Some projects still mark entities via base classes or docblocks. If you want to be
        // super strict, return false; otherwise, treat as non-entity.
        return false;
    }
}
