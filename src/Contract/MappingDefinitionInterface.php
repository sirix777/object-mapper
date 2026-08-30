<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Contract;

/**
 * Read and registry contract for mapping definitions, not an executable extension SPI.
 *
 * Custom executable behavior uses CustomObjectMapperInterface with
 * CustomMappingDefinition, or an application-owned closed opaque ID with
 * ProviderCustomMappingDefinition.
 */
interface MappingDefinitionInterface
{
    /** @return class-string */
    public function source(): string;

    /** @return class-string */
    public function target(): string;

    public function key(): string;
}
