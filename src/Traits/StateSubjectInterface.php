<?php

declare(strict_types=1);

namespace Survos\StateBundle\Traits;

/**
 * A row whose only job is to carry a workflow: an id, a marking and the metadata its listeners need.
 *
 * Use it when the thing being tracked is not already an entity of its own (an issue in a vendor
 * delivery, a file in a bucket, a page of an API) and a dedicated entity would exist only to hold a
 * marking. Implemented by {@see StateSubjectTrait}.
 */
interface StateSubjectInterface extends MarkingInterface
{
    public function getId(): string;

    public function meta(string $key, mixed $default = null): mixed;

    /** @param array<string, mixed> $values merged over the existing metadata */
    public function withMeta(array $values): static;
}
