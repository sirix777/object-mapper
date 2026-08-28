<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Metadata;

use ReflectionClass;
use ReflectionType;

/** @internal */
final readonly class TransformerMetadata
{
    /**
     * @param class-string            $class
     * @param ReflectionClass<object> $inputDeclaringClass
     * @param ReflectionClass<object> $outputDeclaringClass
     */
    public function __construct(
        public string $class,
        public ReflectionType $inputType,
        public ReflectionClass $inputDeclaringClass,
        public ReflectionType $outputType,
        public ReflectionClass $outputDeclaringClass,
        public ?string $fileHash,
    ) {}
}
