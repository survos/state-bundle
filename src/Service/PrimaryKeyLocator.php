<?php
declare(strict_types=1);

namespace Survos\StateBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

final class PrimaryKeyLocator
{
    /** @var array<string, string|null> */
    private array $fieldCache = [];

    public function __construct(
        private readonly EntityManagerInterface $em
    ) {}

    /**
     * Return the single PK field name for the entity class, or null for composite/none.
     */
    public function idFieldFor(string $fqcn): ?string
    {
        if (\array_key_exists($fqcn, $this->fieldCache)) {
            return $this->fieldCache[$fqcn];
        }
        try {
            $meta = $this->em->getClassMetadata($fqcn);
            $ids = $meta->getIdentifierFieldNames();
            $this->fieldCache[$fqcn] = (\count($ids) === 1) ? $ids[0] : null;
            return $this->fieldCache[$fqcn];
        } catch (\Throwable) {
            $this->fieldCache[$fqcn] = null;
            return null;
        }
    }

    /**
     * Resolve the id value for an entity (as string) using metadata first;
     * falls back to property accessor('id') only if needed.
     */
    public function resolveId(object $entity, PropertyAccessorInterface $pa): ?string
    {
        $fqcn = $entity::class;

        // Metadata path (preferred)
        try {
            $meta = $this->em->getClassMetadata($fqcn);
            $values = $meta->getIdentifierValues($entity);
            if (\count($values) === 1) {
                return (string)\array_values($values)[0];
            }
        } catch (\Throwable) {
            // fall through
        }

        // Conventional "id" property as last-resort
        try {
            $val = $pa->getValue($entity, 'id');
            return $val !== null ? (string)$val : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
