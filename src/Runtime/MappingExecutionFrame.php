<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Runtime;

use Sirix\ObjectMapper\Definition\CustomMappingDefinition;
use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Definition\ProviderCustomMappingDefinition;
use Sirix\ObjectMapper\Definition\SourceMatchMode;
use Sirix\ObjectMapper\Generator\GeneratedMapperInterface;

/** @internal */
final readonly class MappingExecutionFrame
{
    /**
     * @param array<string, array{definition: CustomMappingDefinition|MappingDefinition|ProviderCustomMappingDefinition, mapper: null|GeneratedMapperInterface, hasStructuralMappings: bool}> $dependencies
     * @param array<string, array{source: string, target: string, parameter: string, expected: string, elementTarget: class-string, sourceMatch: SourceMatchMode}>                            $collections
     */
    public function __construct(
        public ?self $previous,
        public MappingDefinition $definition,
        public MappingExecution $execution,
        public array $dependencies,
        public array $collections,
    ) {}
}
