<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Runtime;

use Sirix\ObjectMapper\Contract\MappingRegistryInterface;
use Sirix\ObjectMapper\Contract\ObjectMapperInterface;
use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Exception\MappingCompilationFailed;
use Sirix\ObjectMapper\Exception\MappingExecutionFailed;
use Sirix\ObjectMapper\Generator\MapperCache;
use Throwable;

use function implode;
use function sprintf;

final readonly class ObjectMapper implements ObjectMapperInterface
{
    public function __construct(
        private MappingRegistryInterface $mappingRegistry,
        private MapperCache $mapperCache,
    ) {}

    /**
     * @template T of object
     *
     * @param class-string<T> $target
     *
     * @return T
     */
    public function map(object $source, string $target): object
    {
        $mappingDefinition   = $this->mappingRegistry->get($source::class, $target);
        $generatedMapper     = $this->mapperCache->get($mappingDefinition);

        try {
            $mapped = $generatedMapper->map($source);
        } catch (MappingExecutionFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new MappingExecutionFailed(sprintf(
                'Could not execute mapping %s.',
                $mappingDefinition->key(),
            ), $exception->getCode(), previous: $exception);
        }

        return $this->assertTarget($mapped, $target, $mappingDefinition);
    }

    /**
     * Compiles every registered mapping and returns the successfully warmed pair keys.
     *
     * @return list<string>
     */
    public function warmup(): array
    {
        $warmed   = [];
        $failures = [];

        foreach ($this->mappingRegistry->all() as $definition) {
            try {
                $this->mapperCache->warm($definition);
                $warmed[] = $definition->key();
            } catch (Throwable $exception) {
                $failures[] = sprintf('%s: %s', $definition->key(), $exception->getMessage());
            }
        }

        if ([] !== $failures) {
            throw new MappingCompilationFailed("Mapper warmup failed:\n" . implode("\n", $failures));
        }

        return $warmed;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $target
     *
     * @return T
     */
    private function assertTarget(object $mapped, string $target, MappingDefinition $mappingDefinition): object
    {
        if (! $mapped instanceof $target) {
            throw new MappingExecutionFailed(sprintf(
                'Generated mapping %s returned %s instead of %s.',
                $mappingDefinition->key(),
                $mapped::class,
                $target,
            ));
        }

        return $mapped;
    }
}
