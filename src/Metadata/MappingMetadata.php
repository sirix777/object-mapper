<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Metadata;

/** @internal */
final readonly class MappingMetadata
{
    /**
     * @param list<TargetParameter> $parameters
     */
    public function __construct(
        public string $source,
        public string $target,
        public array $parameters,
        public ?string $sourceFileHash,
        public ?string $targetFileHash,
    ) {}
}
