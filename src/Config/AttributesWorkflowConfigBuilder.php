<?php
declare(strict_types=1);

namespace Survos\StateBundle\Config;

use ReflectionClass;
use ReflectionClassConstant;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Finder\Finder;

// Attribute classes (current)
use Survos\StateBundle\Attribute\Workflow as WFAttr;
use Survos\StateBundle\Attribute\Place as PlaceAttr;
use Survos\StateBundle\Attribute\Transition as TransAttr;

/**
 * Scans workflow definition files (classes/interfaces/traits with attributes) and
 * builds a framework.workflows config payload. Ensures:
 *  - Place metadata is attached PER PLACE (so Symfony's MetadataStore populates placesMetadata)
 *  - Transition guard stays at top-level (not duplicated in metadata)
 *  - Async transitions are tracked for external wiring (Messenger, etc.)
 *
 * Returns:
 *  [
 *    'workflows' => array<string, array>,   // framework.workflows.workflows payload
 *    'async_transitions' => string[],        // list of transition names with async: true
 *    'resources' => FileResource[],          // for container tracking
 *  ]
 */
final class AttributesWorkflowConfigBuilder
{
    /**
     * @param string[] $workflowDirs
     * @return array{
     *   workflows: array,
     *   async_transitions: string[],
     *   resources: FileResource[]
     * }
     */
    public static function build(array $workflowDirs): array
    {
        // ensure dirs exist (requested)
        foreach ($workflowDirs as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }

        $workflows = [];
        $asyncTransitions = [];
        $resources = [];

        $finder = (new Finder())->files()->in($workflowDirs)->name('*.php');

        foreach ($finder as $file) {
            $path = $file->getRealPath();
            if (!$path) {
                continue;
            }
            $resources[] = new FileResource($path);

            // Resolve FQCN in a very lightweight way
            $code = file_get_contents($path) ?: '';
            if (!preg_match('/namespace\s+([^;]+);/m', $code, $ns)) {
                continue;
            }
            if (!preg_match('/\b(class|interface|trait)\s+([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)/m', $code, $ci)) {
                continue;
            }
            $fqcn = trim($ns[1]) . '\\' . trim($ci[2]);

            if (!class_exists($fqcn) && !interface_exists($fqcn) && !trait_exists($fqcn)) {
                try {
                    require_once $path;
                } catch (\Throwable) {
                    // ignore load failures and continue
                }
            }
            if (!class_exists($fqcn) && !interface_exists($fqcn) && !trait_exists($fqcn)) {
                continue;
            }

            $rc = new ReflectionClass($fqcn);

            // Support both current and (optionally) legacy Workflow attributes if present.
            $wfAttr = self::findFirstAttributeInstance($rc, self::workflowAttrCandidates());
            if (!$wfAttr) {
                // Skip files that are not workflow definition carriers
                continue;
            }

            // Basic workflow wiring
            $wfName   = property_exists($wfAttr, 'name') && $wfAttr->name ? $wfAttr->name : $rc->getShortName();
            $wfType   = property_exists($wfAttr, 'type') && $wfAttr->type ? $wfAttr->type : 'state_machine';
            $supports = property_exists($wfAttr, 'supports') && is_array($wfAttr->supports) ? $wfAttr->supports : [];
            $initial  = property_exists($wfAttr, 'initial') ? $wfAttr->initial : null;

            // Build places as an ASSOCIATIVE MAP so Symfony can populate placesMetadata
            //   places => [
            //     'place_name' => ['metadata' => [...]] | []
            //   ]
            $placesAssoc = [];
            $transitions = [];

            /** @var ReflectionClassConstant $const */
            foreach ($rc->getReflectionConstants() as $const) {
                $value = (string) $const->getValue();

                // PLACE
                if ($p = self::findFirstAttributeInstance($const, self::placeAttrCandidates())) {
                    // Start from attribute-supplied metadata (already merged in your attribute constructors)
                    $pm = [];
                    if (property_exists($p, 'metadata') && is_array($p->metadata)) {
                        $pm = $p->metadata;
                    }

                    // Ensure type safety for 'next'
                    if (isset($pm['next']) && !is_array($pm['next'])) {
                        $pm['next'] = (array) $pm['next'];
                    }

                    $placesAssoc[$value] = $pm ? ['metadata' => $pm] : [];

                    // Initial for state_machine
                    if (method_exists($p, 'getIsInitial') && $p->getIsInitial() && $wfType === 'state_machine' && !$initial) {
                        $initial = $value;
                    } elseif (property_exists($p, 'initial') && $p->initial && $wfType === 'state_machine' && !$initial) {
                        $initial = $value;
                    }
                }

                // TRANSITION
                if ($t = self::findFirstAttributeInstance($const, self::transitionAttrCandidates())) {
                    $name = $value;

                    $item = [
                        'name' => $name,
                        'from' => (array) (property_exists($t, 'from') ? $t->from : []),
                        'to'   => (array) (property_exists($t, 'to') ? (is_array($t->to) ? $t->to : [$t->to]) : []),
                    ];

                    // Guard must be top-level for ExpressionLanguage guard to work
                    if (property_exists($t, 'guard') && $t->guard) {
                        $item['guard'] = $t->guard;
                    }

                    // Start from attribute metadata (your attribute constructors already merged info/description/async/transport/next)
                    $md = [];
                    if (property_exists($t, 'metadata') && is_array($t->metadata)) {
                        $md = $t->metadata;
                    }

                    // Do not duplicate guard in metadata
                    if (isset($md['guard'])) {
                        unset($md['guard']);
                    }

                    // Normalize async and track
                    $isAsync = false;
                    if (property_exists($t, 'async') && $t->async) {
                        $isAsync = true;
                    }
                    if (isset($md['async']) && $md['async']) {
                        $isAsync = true;
                    }
                    if ($isAsync) {
                        $md['async'] = true;
                        $asyncTransitions[] = $name;
                    }

                    // Normalize 'next' to array if present
                    if (isset($md['next']) && !is_array($md['next'])) {
                        $md['next'] = (array) $md['next'];
                    }

                    if ($md) {
                        $item['metadata'] = $md;
                    }

                    $transitions[] = $item;
                }
            }

            // Build node
            $node = [
                'type'        => $wfType,
                'supports'    => $supports ?: [$fqcn],
                // IMPORTANT: associative places so Symfony populates placesMetadata
                'places'      => $placesAssoc,
                'transitions' => $transitions,
                'marking_store' => [
                    'type'     => 'method',
                    'property' => 'marking', // single-state marking for state_machine
                ],
            ];

            if ($initial) {
                $node['initial_marking'] = $wfType === 'state_machine' ? $initial : (array) $initial;
            }

            // NOTE: We deliberately do NOT stuff per-place data under workflow-level metadata['places'].
            // If you truly have workflow-level metadata (applies to the entire workflow), you can add it here:
            // $node['metadata'] = ['foo' => 'bar'];

            $workflows[$wfName] = $node;
        }

        return [
            'workflows'         => $workflows,
            'async_transitions' => array_values(array_unique($asyncTransitions)),
            'resources'         => $resources,
        ];
    }

    /**
     * Helper: find the first attribute instance from a list of candidate class names.
     */
    private static function findFirstAttributeInstance(\Reflector $r, array $classes): ?object
    {
        foreach ($classes as $name) {
            // Skip candidates that don't exist at runtime
            if (!class_exists($name)) {
                continue;
            }
            $attrs = $r->getAttributes($name);
            if ($attrs) {
                return $attrs[0]->newInstance();
            }
        }
        return null;
    }

    /**
     * Candidate class names for Workflow attribute (current + optional legacy).
     * Add legacy FQCNs here if you maintain BC.
     *
     * @return string[]
     */
    private static function workflowAttrCandidates(): array
    {
        $candidates = [WFAttr::class];
        // e.g. legacy: Survos\StateBundle\Attribute\OldWorkflow
        if (class_exists('Survos\\StateBundle\\Attribute\\OldWorkflow')) {
            $candidates[] = 'Survos\\StateBundle\\Attribute\\OldWorkflow';
        }
        return $candidates;
    }

    /**
     * Candidate class names for Place attribute (current + optional legacy).
     *
     * @return string[]
     */
    private static function placeAttrCandidates(): array
    {
        $candidates = [PlaceAttr::class];
        if (class_exists('Survos\\StateBundle\\Attribute\\OldPlace')) {
            $candidates[] = 'Survos\\StateBundle\\Attribute\\OldPlace';
        }
        return $candidates;
    }

    /**
     * Candidate class names for Transition attribute (current + optional legacy).
     *
     * @return string[]
     */
    private static function transitionAttrCandidates(): array
    {
        $candidates = [TransAttr::class];
        if (class_exists('Survos\\StateBundle\\Attribute\\OldTransition')) {
            $candidates[] = 'Survos\\StateBundle\\Attribute\\OldTransition';
        }
        return $candidates;
    }
}
