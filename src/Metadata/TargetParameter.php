<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Metadata;

use ReflectionClass;
use ReflectionType;

/** @internal */
final readonly class TargetParameter
{
    /** @param ReflectionClass<object> $declaringClass */
    public function __construct(
        public string $name,
        public ?SourceMember $sourceMember,
        public bool $hasDefault,
        public ?ReflectionType $type,
        public ReflectionClass $declaringClass,
        public ?TransformerMetadata $transformer = null,
        public ?NestedMappingMetadata $nestedMapping = null,
    ) {}
}
