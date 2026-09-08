<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Runtime;

use Sirix\ObjectMapper\Definition\CustomMappingDefinition;
use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Definition\ProviderCustomMappingDefinition;
use Sirix\ObjectMapper\Definition\SourceMatchMode;
use Sirix\ObjectMapper\Generator\GeneratedMapperInterface;
use stdClass;

/** @internal */
final class MappingExecution
{
    /** Unique, inert identity for authenticating pending collection failures. */
    public readonly object $provenance;

    /** @var array<string, array{dependencies: array<string, array{definition: CustomMappingDefinition|MappingDefinition|ProviderCustomMappingDefinition, mapper: null|GeneratedMapperInterface, hasStructuralMappings: bool}>, collections: array<string, array{source: string, target: string, parameter: string, expected: string, elementTarget: class-string, sourceMatch: SourceMatchMode}>}> */
    public array $mappings = [];

    public function __construct()
    {
        $this->provenance = new stdClass();
    }
}
