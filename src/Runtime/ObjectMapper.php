<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Runtime;

use Sirix\ObjectMapper\Contract\CustomObjectMapperProviderInterface;
use Sirix\ObjectMapper\Contract\MappingDefinitionInterface;
use Sirix\ObjectMapper\Contract\MappingRegistryInterface;
use Sirix\ObjectMapper\Contract\ObjectMapperInterface;
use Sirix\ObjectMapper\Definition\CustomMappingDefinition;
use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Definition\ProviderCustomMappingDefinition;
use Sirix\ObjectMapper\Definition\SourceMatchMode;
use Sirix\ObjectMapper\Exception\MappingCompilationFailed;
use Sirix\ObjectMapper\Exception\MappingExecutionFailed;
use Sirix\ObjectMapper\Exception\MappingNotRegistered;
use Sirix\ObjectMapper\Generator\MapperCache;
use Sirix\ObjectMapper\Metadata\MappingMetadata;
use Throwable;

use function array_key_last;
use function array_keys;
use function array_pop;
use function array_search;
use function array_shift;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function get_parent_class;
use function implode;
use function ksort;
use function preg_match;
use function sort;
use function sprintf;

final readonly class ObjectMapper implements ObjectMapperInterface
{
    private CustomMappingExecutor $customMappingExecutor;

    public function __construct(
        private MappingRegistryInterface $mappingRegistry,
        private MapperCache $mapperCache,
        ?CustomObjectMapperProviderInterface $customObjectMapperProvider = null,
        ?CustomMappingExecutor $customMappingExecutor = null,
    ) {
        $this->customMappingExecutor = $customMappingExecutor ?? new CustomMappingExecutor($customObjectMapperProvider);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $target
     *
     * @return T
     */
    public function map(object $source, string $target): object
    {
        $mappingDefinition = $this->definitionFor($source, $target);

        if ($mappingDefinition instanceof MappingDefinition) {
            $mapped = $this->mapperCache->map($mappingDefinition, $source);
        } elseif ($mappingDefinition instanceof CustomMappingDefinition || $mappingDefinition instanceof ProviderCustomMappingDefinition) {
            $mapped = $this->customMappingExecutor->map($mappingDefinition, $source);
        } else {
            throw new MappingExecutionFailed(sprintf(
                'Mapping %s has an unsupported definition type %s.',
                $mappingDefinition->key(),
                $mappingDefinition::class,
            ));
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
        $definitions       = $this->conventionalDefinitions();
        $mappingMetadata   = [];
        $failures          = [];
        $reportedCycles    = [];
        $graph             = $this->dependencyGraph($definitions, $mappingMetadata, $failures, $reportedCycles);

        if ([] !== $failures) {
            throw new MappingCompilationFailed("Mapper warmup failed:\n" . implode("\n", $failures));
        }

        $warmed         = [];
        $states         = [];
        foreach (array_keys($definitions) as $key) {
            $this->warmDependencies($key, $definitions, $mappingMetadata, $graph, $states, [], $warmed, $failures, $reportedCycles);
        }

        if ([] !== $failures) {
            throw new MappingCompilationFailed("Mapper warmup failed:\n" . implode("\n", $failures));
        }

        return $warmed;
    }

    /** @param class-string $target */
    private function definitionFor(object $source, string $target): MappingDefinitionInterface
    {
        try {
            return $this->mappingRegistry->get($source::class, $target);
        } catch (MappingNotRegistered $original) {
            $parent = get_parent_class($source);
            if (false === $parent) {
                throw $original;
            }

            if (! SourceMatcher::matches($source, $parent, SourceMatchMode::CycleProxy)) {
                throw $original;
            }

            try {
                $definition = $this->mappingRegistry->get($parent, $target);
            } catch (MappingNotRegistered) {
                throw $original;
            }

            if (! SourceMatcher::matches($source, $parent, SourceMatcher::modeFor($definition))) {
                throw $original;
            }

            return $definition;
        }
    }

    /** @return array<string, MappingDefinition> */
    private function conventionalDefinitions(): array
    {
        try {
            $definitions = [];
            foreach ($this->mappingRegistry->all() as $definition) {
                if ($definition instanceof MappingDefinition) {
                    $definitions[$definition->key()] = $definition;
                }
            }
        } catch (Throwable) {
            throw new MappingCompilationFailed('Could not enumerate registered mappings.');
        }

        ksort($definitions);

        return $definitions;
    }

    /**
     * @param array<string, MappingDefinition> $definitions
     * @param array<string, MappingMetadata>   $mappingMetadata
     * @param list<string>                     $failures
     * @param array<string, true>              $reportedCycles
     *
     * @return array<string, list<string>>
     */
    private function dependencyGraph(array &$definitions, array &$mappingMetadata, array &$failures, array &$reportedCycles): array
    {
        $graph     = [];
        $pending   = array_keys($definitions);
        $processed = [];
        while ([] !== $pending) {
            $key = array_shift($pending);
            if (isset($processed[$key])) {
                continue;
            }

            $definition = $definitions[$key];

            try {
                $metadata    = $mappingMetadata[$key] ??= $this->mapperCache->metadata($definition);
                $graph[$key] = [];
                foreach ($this->mapperCache->warmupDependencies($metadata) as $dependencyKey => $dependency) {
                    $existingDefinition              = $definitions[$dependencyKey] ?? null;
                    if (null !== $existingDefinition && $existingDefinition !== $dependency['definition']) {
                        $failures[] = sprintf('%s: Conflicting compiled dependency snapshots.', $dependencyKey);

                        continue;
                    }

                    $definitions[$dependencyKey]     = $dependency['definition'];
                    $mappingMetadata[$dependencyKey] = $dependency['metadata'];
                    $graph[$key][]                   = $dependencyKey;

                    if ($existingDefinition !== $dependency['definition']) {
                        unset($processed[$dependencyKey]);
                    }

                    $pending[] = $dependencyKey;
                }

                $graph[$key] = array_values(array_unique($graph[$key]));
                sort($graph[$key]);
                $processed[$key] = true;
            } catch (Throwable $exception) {
                $trustedFailureMessage = $this->mapperCache->trustedWarmupFailureMessage($exception);
                $cycle                 = null === $trustedFailureMessage ? null : $this->trustedMetadataCycle($trustedFailureMessage);
                if (null !== $cycle) {
                    $cycleKey = implode("\0", $cycle);
                    if (! isset($reportedCycles[$cycleKey])) {
                        $failures[]                = sprintf('Mapper warmup dependency cycle detected: %s.', implode(' -> ', $cycle));
                        $reportedCycles[$cycleKey] = true;
                    }

                    continue;
                }

                $failures[]            = sprintf(
                    '%s: %s',
                    $key,
                    $trustedFailureMessage ?? 'Could not compile mapping metadata.',
                );
            }
        }

        ksort($graph);

        return $graph;
    }

    /**
     * Extracts a cycle only from the factory's exact, trusted nested-rule diagnostic.
     *
     * @return null|list<string>
     */
    private function trustedMetadataCycle(string $message): ?array
    {
        $className = '[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*';
        $pair      = sprintf('%1$s->%1$s', $className);
        $pattern   = sprintf(
            '~\ACannot compile mapping %1$s -> %1$s for parameter \$[A-Za-z_][A-Za-z0-9_]*: Configured (?:nested|collection) mapping: mapping dependency cycle detected: (?<cycle>%2$s(?: -> %2$s)+)\.\z~D',
            $className,
            $pair,
        );

        if (1 !== preg_match($pattern, $message, $matches)) {
            return null;
        }

        $cycle = explode(' -> ', $matches['cycle']);
        if (3 > count($cycle) || $cycle[0] !== $cycle[array_key_last($cycle)]) {
            return null;
        }

        return $this->canonicalCycle($cycle);
    }

    /**
     * @param array<string, MappingDefinition>             $definitions
     * @param array<string, MappingMetadata>               $mappingMetadata
     * @param array<string, list<string>>                  $graph
     * @param array<string, 'failed'|'visited'|'visiting'> $states
     * @param list<string>                                 $stack
     * @param list<string>                                 $warmed
     * @param list<string>                                 $failures
     * @param array<string, true>                          $reportedCycles
     */
    private function warmDependencies(
        string $key,
        array $definitions,
        array $mappingMetadata,
        array $graph,
        array &$states,
        array $stack,
        array &$warmed,
        array &$failures,
        array &$reportedCycles,
    ): void {
        if ('visited' === ($states[$key] ?? null) || 'failed' === ($states[$key] ?? null)) {
            return;
        }

        if ('visiting' === ($states[$key] ?? null)) {
            $cycleStart = array_search($key, $stack, true);
            $cycle      = false === $cycleStart ? [...$stack, $key] : [...array_slice($stack, $cycleStart), $key];
            $cycle      = $this->canonicalCycle($cycle);
            $cycleKey   = implode("\0", $cycle);

            if (! isset($reportedCycles[$cycleKey])) {
                $failures[]                  = sprintf('Mapper warmup dependency cycle detected: %s.', implode(' -> ', $cycle));
                $reportedCycles[$cycleKey]   = true;
            }

            $states[$key] = 'failed';

            return;
        }

        $states[$key]        = 'visiting';
        $stack[]             = $key;
        $hasFailedDependency = false;
        foreach ($graph[$key] ?? [] as $dependency) {
            $this->warmDependencies($dependency, $definitions, $mappingMetadata, $graph, $states, $stack, $warmed, $failures, $reportedCycles);
            $hasFailedDependency = $hasFailedDependency || 'visited' !== ($states[$dependency] ?? null);
        }

        if ($hasFailedDependency) {
            $states[$key] = 'failed';

            return;
        }

        try {
            $metadata = $mappingMetadata[$key] ?? null;
            if (! $metadata instanceof MappingMetadata) {
                throw new MappingCompilationFailed('Could not compile mapping metadata.');
            }

            $this->mapperCache->warm($definitions[$key], $metadata);
            $warmed[] = $key;
        } catch (Throwable) {
            $failures[]   = sprintf('%s: Could not warm mapping.', $key);
            $states[$key] = 'failed';

            return;
        }

        $states[$key] = 'visited';
    }

    /**
     * Rotates a closed cycle so equivalent traversals have one deterministic representation.
     *
     * @param list<string> $cycle
     *
     * @return list<string>
     */
    private function canonicalCycle(array $cycle): array
    {
        $closingKey = array_pop($cycle);
        if ([] === $cycle || null === $closingKey) {
            return $cycle;
        }

        $canonical = $cycle;
        foreach ($cycle as $offset => $_) {
            $candidate = [...array_slice($cycle, $offset), ...array_slice($cycle, 0, $offset)];
            if (implode("\0", $candidate) < implode("\0", $canonical)) {
                $canonical = $candidate;
            }
        }

        $canonical[] = $canonical[0];

        return $canonical;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $target
     *
     * @return T
     */
    private function assertTarget(object $mapped, string $target, MappingDefinitionInterface $mappingDefinition): object
    {
        if (! $mapped instanceof $target) {
            if ($mappingDefinition instanceof ProviderCustomMappingDefinition) {
                throw new MappingExecutionFailed(sprintf(
                    'Could not execute mapping %s.',
                    $mappingDefinition->key(),
                ));
            }

            throw new MappingExecutionFailed(sprintf(
                'Mapping %s returned %s instead of an instance of %s.',
                $mappingDefinition->key(),
                $mapped::class,
                $target,
            ));
        }

        return $mapped;
    }
}
