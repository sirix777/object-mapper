<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Runtime;

use Sirix\ObjectMapper\Contract\CustomObjectMapperInterface;
use Sirix\ObjectMapper\Contract\CustomObjectMapperProviderInterface;
use Sirix\ObjectMapper\Definition\CustomMappingDefinition;
use Sirix\ObjectMapper\Definition\ProviderCustomMappingDefinition;
use Sirix\ObjectMapper\Exception\MappingExecutionFailed;
use Throwable;

use function sprintf;

/** @internal */
final readonly class CustomMappingExecutor
{
    public function __construct(private ?CustomObjectMapperProviderInterface $customObjectMapperProvider = null) {}

    public function map(CustomMappingDefinition|ProviderCustomMappingDefinition $mappingDefinition, object $source): object
    {
        try {
            $mapper = $mappingDefinition instanceof CustomMappingDefinition
                ? $mappingDefinition->mapper
                : $this->providerMapper($mappingDefinition);

            return $mapper->map($source);
        } catch (Throwable) {
            throw new MappingExecutionFailed(sprintf(
                'Could not execute mapping %s.',
                $mappingDefinition->key(),
            ));
        }
    }

    private function providerMapper(ProviderCustomMappingDefinition $providerCustomMappingDefinition): CustomObjectMapperInterface
    {
        if (! $this->customObjectMapperProvider instanceof CustomObjectMapperProviderInterface) {
            throw new MappingExecutionFailed('A custom mapper provider is required.');
        }

        return $this->customObjectMapperProvider->get($providerCustomMappingDefinition->mapperId());
    }
}
