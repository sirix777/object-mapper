<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Contract;

interface MappingRegistryInterface
{
    /**
     * @param class-string $source
     * @param class-string $target
     */
    public function get(string $source, string $target): MappingDefinitionInterface;

    /**
     * @return iterable<MappingDefinitionInterface>
     */
    public function all(): iterable;
}
