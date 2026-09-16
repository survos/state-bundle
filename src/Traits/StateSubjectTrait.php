<?php

declare(strict_types=1);

namespace Survos\StateBundle\Traits;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Id, marking and a metadata blob: the columns of a generic workflow subject.
 *
 * A trait, not an entity, so state-bundle ships no table. An app that wants generic subjects maps
 * one root entity itself, typically single-table with a discriminator column naming the kind of
 * subject, and gives each kind a small subclass with its own #[Workflow]:
 *
 *     #[ORM\Entity]
 *     #[ORM\Table(name: 'state_subject')]
 *     #[ORM\InheritanceType('SINGLE_TABLE')]
 *     #[ORM\DiscriminatorColumn(name: 'class', type: 'string', length: 64)]
 *     #[ORM\DiscriminatorMap(['ndnp_issue' => NdnpIssue::class])]
 *     abstract class StateSubject implements StateSubjectInterface
 *     {
 *         use StateSubjectTrait;
 *     }
 *
 * The metadata carries everything a transition listener needs to act, so an async consumer loads
 * the row by id and works from that — never from a shared file another worker may be writing.
 */
trait StateSubjectTrait
{
    use MarkingTrait;

    /** A stable natural key where one exists (e.g. an issue id), so re-registering is an upsert. */
    #[ORM\Id]
    #[ORM\Column(length: 190)]
    public string $id;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    public array $metadata = [];

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $updatedAt = null;

    public function getId(): string
    {
        return $this->id;
    }

    public function meta(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /** @param array<string, mixed> $values */
    public function withMeta(array $values): static
    {
        $this->metadata = array_replace($this->metadata, $values);
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
