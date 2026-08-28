<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Contract;

use Sirix\ObjectMapper\Definition\MappingDefinition;

interface MappingRegistryInterface
{
    /**
     * @param class-string $source
     * @param class-string $target
     */
    public function get(string $source, string $target): MappingDefinition;

    /**
     * @return iterable<MappingDefinition>
     */
    public function all(): iterable;
}
