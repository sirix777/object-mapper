<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Metadata;

use Sirix\ObjectMapper\Definition\SourceMatchMode;

/** @internal */
final readonly class NestedMappingMetadata
{
    /**
     * @param 'collection'|'nested' $operation
     * @param class-string          $source
     * @param class-string          $target
     * @param null|class-string     $elementSource
     * @param null|class-string     $elementTarget
     */
    public function __construct(
        public string $operation,
        public string $source,
        public string $target,
        public bool $nullable,
        public string $dependencyFingerprint,
        public ?string $elementSource = null,
        public ?string $elementTarget = null,
        public SourceMatchMode $sourceMatch = SourceMatchMode::Exact,
    ) {}
}
