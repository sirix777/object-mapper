<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Runtime;

use Sirix\ObjectMapper\Contract\CustomObjectMapperInterface;
use Sirix\ObjectMapper\Contract\CustomObjectMapperProviderInterface;
use Sirix\ObjectMapper\Definition\CustomMappingDefinition;
use Sirix\ObjectMapper\Definition\ProviderCustomMappingDefinition;
use Sirix\ObjectMapper\Exception\MappingExecutionFailed;
use Sirix\ObjectMapper\Exception\MappingFailureReason;
use Throwable;

use function sprintf;

/** @internal */
final readonly class CustomMappingExecutor
{
    public function __construct(private ?CustomObjectMapperProviderInterface $customObjectMapperProvider = null) {}

    public function map(CustomMappingDefinition|ProviderCustomMappingDefinition $mappingDefinition, object $source): object
    {
        $mapper = $mappingDefinition instanceof CustomMappingDefinition
            ? $mappingDefinition->mapper
            : $this->resolveProviderMapper($mappingDefinition);

        try {
            return $mapper->map($source);
        } catch (Throwable) {
            throw new MappingExecutionFailed(sprintf(
                'Could not execute mapping %s.',
                $mappingDefinition->key(),
            ), reason: MappingFailureReason::CustomMapperFailed);
        }
    }

    private function resolveProviderMapper(ProviderCustomMappingDefinition $providerCustomMappingDefinition): CustomObjectMapperInterface
    {
        if (! $this->customObjectMapperProvider instanceof CustomObjectMapperProviderInterface) {
            throw new MappingExecutionFailed(sprintf(
                'Could not execute mapping %s.',
                $providerCustomMappingDefinition->key(),
            ), reason: MappingFailureReason::ProviderUnavailable);
        }

        try {
            return $this->customObjectMapperProvider->get($providerCustomMappingDefinition->mapperId());
        } catch (Throwable) {
            throw new MappingExecutionFailed(sprintf(
                'Could not execute mapping %s.',
                $providerCustomMappingDefinition->key(),
            ), reason: MappingFailureReason::ProviderResolutionFailed);
        }
    }
}
