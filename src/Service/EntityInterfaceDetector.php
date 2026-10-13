<?php

declare(strict_types=1);

namespace Survos\StateBundle\Service;

use Doctrine\ORM\EntityManagerInterface;

class EntityInterfaceDetector
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function getEntitiesImplementing(string $interface): array
    {
        $entities = [];
        $metadatas = $this->em->getMetadataFactory()->getAllMetadata();

        foreach ($metadatas as $metadata) {
            $class = $metadata->getName();
            if (is_subclass_of($class, $interface)) {
                $entities[] = $class;
            }
        }

        return $entities;
    }
}
