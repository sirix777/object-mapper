<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Runtime;

use Sirix\ObjectMapper\Definition\SourceMatchMode;

/** @internal */
interface NestedMappingRuntimeInterface
{
    /**
     * @param class-string $source
     * @param class-string $target
     */
    public function mapNested(object $value, string $source, string $target, SourceMatchMode $sourceMatchMode): object;

    /**
     * Throws a structural generated-mapper failure without rendering an input value.
     *
     * @param class-string $source
     * @param class-string $target
     */
    public function collectionElementTypeFailure(
        string $source,
        string $target,
        string $parameter,
        int|string $key,
        string $expected,
        mixed $actual,
    ): never;
}
