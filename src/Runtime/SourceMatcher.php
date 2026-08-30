<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Runtime;

use Sirix\ObjectMapper\Contract\MappingDefinitionInterface;
use Sirix\ObjectMapper\Definition\CustomMappingDefinition;
use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Definition\SourceMatchMode;

use function get_parent_class;
use function is_a;

/** @internal */
final class SourceMatcher
{
    public static function modeFor(MappingDefinitionInterface $mappingDefinition): SourceMatchMode
    {
        if ($mappingDefinition instanceof MappingDefinition || $mappingDefinition instanceof CustomMappingDefinition) {
            return $mappingDefinition->sourceMatch;
        }

        return SourceMatchMode::Exact;
    }

    /** @param class-string $registeredSource */
    public static function matches(object $value, string $registeredSource, SourceMatchMode $sourceMatchMode): bool
    {
        if ($value::class === $registeredSource) {
            return true;
        }

        return SourceMatchMode::CycleProxy === $sourceMatchMode
            && is_a($value, 'Cycle\ORM\EntityProxyInterface')
            && get_parent_class($value) === $registeredSource;
    }
}
