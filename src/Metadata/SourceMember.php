<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Metadata;

use LogicException;
use ReflectionClass;
use ReflectionType;

/** @internal */
final readonly class SourceMember
{
    /** @param ReflectionClass<object> $declaringClass */
    public function __construct(
        public string $name,
        public string $kind,
        public ReflectionType $type,
        public ReflectionClass $declaringClass,
        public string $selection = 'convention',
    ) {}

    public function expression(string $sourceVariable): string
    {
        return match ($this->kind) {
            'property' => $sourceVariable . '->' . $this->name,
            'method'   => $sourceVariable . '->' . $this->name . '()',
            default    => throw new LogicException('Unknown source member kind.'),
        };
    }
}
