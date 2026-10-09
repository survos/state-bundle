<?php

declare(strict_types=1);

namespace Survos\StateBundle\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Workflow
{
    public function __construct(
        public ?string $prefix=null, // place prefix
        public string $type='state_machine', // or workflow,
        public array $supports=[],
        public ?string $name=null, // defaults to shortName
        public string|array|null $initial=null, // array if type is workflow
        public \BackedEnum|string|null $initialPlace=null,
    ) {
        if ($initialPlace !== null) {
            if ($initial !== null) {
                throw new \InvalidArgumentException('Use either initial or initialPlace, not both.');
            }
            $value = $initialPlace instanceof \BackedEnum ? $initialPlace->value : $initialPlace;
            if (!is_string($value)) {
                throw new \InvalidArgumentException('initialPlace must be a string or a string-backed enum case.');
            }
            $this->initial = $value;
        }
    }

    public function getPlacePrefix(): ?string
    {
        return $this->prefix;
    }
    
}
