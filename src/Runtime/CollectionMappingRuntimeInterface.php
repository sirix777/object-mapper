<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Runtime;

use Sirix\ObjectMapper\Definition\SourceMatchMode;

/** @internal Optional generated-code capability; never exposes a bound executor. */
interface CollectionMappingRuntimeInterface
{
    /**
     * @param array<int|string, mixed> $values
     * @param class-string             $source
     * @param class-string             $target
     * @param class-string             $elementSource
     * @param class-string             $elementTarget
     *
     * @return null|list<object> null selects the established generated loop
     */
    public function mapCollection(
        array $values,
        string $source,
        string $target,
        string $parameter,
        string $elementSource,
        string $elementTarget,
        SourceMatchMode $sourceMatchMode,
    ): ?array;
}
