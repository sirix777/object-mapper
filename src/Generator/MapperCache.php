<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Generator;

use Fiber;
use InvalidArgumentException;
use Sirix\ObjectMapper\Contract\CustomObjectMapperProviderInterface;
use Sirix\ObjectMapper\Contract\MappingRegistryInterface;
use Sirix\ObjectMapper\Contract\ValueTransformerRegistryInterface;
use Sirix\ObjectMapper\Definition\CustomMappingDefinition;
use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Definition\ProviderCustomMappingDefinition;
use Sirix\ObjectMapper\Definition\SourceMatchMode;
use Sirix\ObjectMapper\Exception\MappingCompilationFailed;
use Sirix\ObjectMapper\Exception\MappingExecutionFailed;
use Sirix\ObjectMapper\Exception\MappingFailureReason;
use Sirix\ObjectMapper\Metadata\MappingMetadata;
use Sirix\ObjectMapper\Metadata\MappingMetadataFactory;
use Sirix\ObjectMapper\Runtime\CollectionMappingRuntimeInterface;
use Sirix\ObjectMapper\Runtime\CustomMappingExecutor;
use Sirix\ObjectMapper\Runtime\GeneratedMappingExecutionFailed;
use Sirix\ObjectMapper\Runtime\MappingExecution;
use Sirix\ObjectMapper\Runtime\MappingExecutionContext;
use Sirix\ObjectMapper\Runtime\MappingExecutionFrame;
use Sirix\ObjectMapper\Runtime\MappingRegistry;
use Sirix\ObjectMapper\Runtime\NestedMappingRuntimeInterface;
use Sirix\ObjectMapper\Runtime\SourceMatcher;
use stdClass;
use Throwable;

use WeakMap;

use function chmod;
use function class_exists;
use function escapeshellarg;
use function exec;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function fileperms;
use function flock;
use function fopen;
use function function_exists;
use function hash;
use function hash_equals;
use function implode;
use function is_array;
use function is_bool;
use function is_dir;
use function is_file;
use function is_float;
use function is_int;
use function is_link;
use function is_null;
use function is_object;
use function is_string;
use function is_writable;
use function ksort;
use function mkdir;
use function opcache_invalidate;
use function preg_match;
use function rename;
use function sprintf;
use function strlen;
use function substr;
use function tempnam;
use function unlink;

/**
 * @internal
 *
 * @phpstan-type Dependency array{definition: CustomMappingDefinition|MappingDefinition|ProviderCustomMappingDefinition, mapper: GeneratedMapperInterface|null, hasStructuralMappings: bool}
 * @phpstan-type CollectionFailureDetails array{source: string, target: string, parameter: string, expected: string, elementTarget: class-string, sourceMatch: SourceMatchMode}
 * @phpstan-type PreparedMapping array{metadata: MappingMetadata, cacheKey: string, mapper: GeneratedMapperInterface, hasStructuralMappings: bool}
 * @phpstan-type ScopeTable array<string, array{dependencies: array<string, Dependency>, collections: array<string, CollectionFailureDetails>}>
 */
final class MapperCache implements NestedMappingRuntimeInterface, CollectionMappingRuntimeInterface
{
    /** @var array<string, GeneratedMapperInterface> */
    private array $mappers = [];

    /** @var WeakMap<object, array{source: string, target: string, parameter: string, expected: string, key: string, actualType: string, provenance: object}> */
    private readonly WeakMap $weakMap;

    private readonly MappingExecutionContext $mappingExecutionContext;

    /** @var WeakMap<object, MappingExecutionContext> Native Fiber ownership. */
    private readonly WeakMap $fiberExecutionContexts;

    /** @var WeakMap<MappingDefinition, PreparedMapping> */
    private readonly WeakMap $preparedMappings;

    /** @var WeakMap<MappingDefinition, ScopeTable> */
    private readonly WeakMap $preparedScopeTables;

    private readonly CustomMappingExecutor $customMappingExecutor;

    public function __construct(
        private readonly MappingMetadataFactory $mappingMetadataFactory,
        private readonly PhpMapperGenerator $phpMapperGenerator,
        private readonly string $cacheDirectory,
        private readonly ValueTransformerRegistryInterface $valueTransformerRegistry,
        private readonly bool $generateOnDemand = false,
        private readonly ?MappingRegistryInterface $mappingRegistry = null,
        ?CustomObjectMapperProviderInterface $customObjectMapperProvider = null,
        ?CustomMappingExecutor $customMappingExecutor = null,
        private readonly bool $reusePreparedMappings = false,
    ) {
        if ('' === $cacheDirectory) {
            throw new InvalidArgumentException('The mapper cache directory cannot be empty.');
        }

        $this->weakMap                   = new WeakMap();
        $this->mappingExecutionContext   = new MappingExecutionContext();
        $this->fiberExecutionContexts    = new WeakMap();
        $this->preparedMappings          = new WeakMap();
        $this->preparedScopeTables       = new WeakMap();
        $this->customMappingExecutor     = $customMappingExecutor ?? new CustomMappingExecutor($customObjectMapperProvider);
    }

    public function get(MappingDefinition $mappingDefinition): GeneratedMapperInterface
    {
        return $this->prepare($mappingDefinition, $this->generateOnDemand)['mapper'];
    }

    public function map(MappingDefinition $mappingDefinition, object $source): object
    {
        $mappingExecutionContext = $this->currentContext();
        $previous                = $mappingExecutionContext->currentFrame;

        if (! $previous instanceof MappingExecutionFrame) {
            return $this->executeRoot($mappingExecutionContext, $mappingDefinition, $source);
        }

        // A reentrant public root is an authority barrier even while preparation runs.
        $mappingExecutionContext->currentFrame = null;

        try {
            return $this->executeRoot($mappingExecutionContext, $mappingDefinition, $source);
        } finally {
            $mappingExecutionContext->currentFrame = $previous;
        }
    }

    public function warm(MappingDefinition $mappingDefinition, ?MappingMetadata $mappingMetadata = null): GeneratedMapperInterface
    {
        return $this->prepare($mappingDefinition, true, $mappingMetadata)['mapper'];
    }

    /** @internal */
    public function metadata(MappingDefinition $mappingDefinition): MappingMetadata
    {
        return $this->mappingMetadataFactory->create($mappingDefinition);
    }

    /**
     * @return array<string, array{definition: MappingDefinition, metadata: MappingMetadata}>
     *
     * @internal
     */
    public function warmupDependencies(MappingMetadata $mappingMetadata): array
    {
        $dependencies = [];
        foreach ($mappingMetadata->parameters as $targetParameter) {
            $nested = $targetParameter->nestedMapping;
            if (null === $nested || $this->mappingMetadataFactory->hasCompiledCustomDependency($mappingMetadata, $nested)) {
                continue;
            }

            $mappingDefinition  = $this->mappingMetadataFactory->compiledConventionalDependency($mappingMetadata, $nested);
            $dependencyMetadata = $this->mappingMetadataFactory->compiledDependencyMetadata($mappingMetadata, $nested);
            if (! $mappingDefinition instanceof MappingDefinition || ! $dependencyMetadata instanceof MappingMetadata) {
                throw new MappingCompilationFailed('Nested mapping dependency does not match its compiled definition.');
            }

            $dependencies[$mappingDefinition->key()] = [
                'definition' => $mappingDefinition,
                'metadata'   => $dependencyMetadata,
            ];
        }

        ksort($dependencies);

        return $dependencies;
    }

    /** @internal */
    public function trustedWarmupFailureMessage(Throwable $throwable): ?string
    {
        return $this->mappingMetadataFactory->trustedCompilationFailureMessage($throwable);
    }

    /**
     * @internal
     *
     * @return null|list<string>
     */
    public function trustedWarmupFailureCycle(Throwable $throwable): ?array
    {
        return $this->mappingMetadataFactory->trustedCompilationFailureCycle($throwable);
    }

    /**
     * @param class-string $source
     * @param class-string $target
     */
    public function mapNested(object $value, string $source, string $target, SourceMatchMode $sourceMatchMode): object
    {
        if (! $this->mappingRegistry instanceof MappingRegistryInterface) {
            throw new MappingCompilationFailed(sprintf(
                'Nested mapping %s -> %s requires a mapping registry.',
                $source,
                $target,
            ));
        }

        if (! SourceMatcher::matches($value, $source, $sourceMatchMode)) {
            throw new MappingExecutionFailed(sprintf(
                'Nested mapping %s -> %s expected an exact %s source object.',
                $source,
                $target,
                $source,
            ));
        }

        $mappingExecutionContext    = $this->currentContext();
        $dependency                 = $this->activeDependency($mappingExecutionContext, $source, $target);
        if (null === $dependency) {
            throw new MappingExecutionFailed('Nested mapping dispatch is not an active declared dependency.');
        }

        $mappingDefinition = $dependency['definition'];

        if ($mappingDefinition instanceof MappingDefinition) {
            $generatedMapper = $dependency['mapper'];
            if (! $generatedMapper instanceof GeneratedMapperInterface) {
                throw new MappingCompilationFailed(sprintf(
                    'Nested mapping %s has no generated mapper.',
                    $mappingDefinition->key(),
                ));
            }

            $mapped = $this->executeConventional($mappingExecutionContext, $mappingDefinition, $value, $generatedMapper, $dependency['hasStructuralMappings']);
        } elseif ($mappingDefinition instanceof CustomMappingDefinition) {
            $mapped = $this->executeCustom($mappingExecutionContext, $mappingDefinition, $value, $this->customMappingExecutor);
        } elseif ($mappingDefinition instanceof ProviderCustomMappingDefinition) {
            $mapped = $this->executeCustom($mappingExecutionContext, $mappingDefinition, $value, $this->customMappingExecutor);
        } else {
            throw new MappingCompilationFailed(sprintf(
                'Nested mapping %s has an unsupported definition type %s.',
                $mappingDefinition->key(),
                $mappingDefinition::class,
            ));
        }

        if (! $mapped instanceof $target) {
            if ($mappingDefinition instanceof ProviderCustomMappingDefinition) {
                throw new MappingExecutionFailed(sprintf(
                    'Could not execute mapping %s.',
                    $mappingDefinition->key(),
                ), reason: MappingFailureReason::UnexpectedTarget);
            }

            throw new MappingCompilationFailed(sprintf(
                'Nested mapping %s returned %s instead of an instance of %s.',
                $mappingDefinition->key(),
                $mapped::class,
                $target,
            ));
        }

        return $mapped;
    }

    public function mapCollection(
        array $values,
        string $source,
        string $target,
        string $parameter,
        string $elementSource,
        string $elementTarget,
        SourceMatchMode $sourceMatchMode,
    ): ?array {
        $mappingExecutionContext     = $this->currentContext();
        $parentFrame                 = $mappingExecutionContext->currentFrame;
        $collection                  = $parentFrame?->collections[$this->collectionKeyId($parameter, $elementSource)] ?? null;
        if (! $parentFrame instanceof MappingExecutionFrame || null === $collection
            || $parentFrame->definition->source !== $source || $parentFrame->definition->target !== $target
            || $collection['elementTarget'] !== $elementTarget
            || $collection['sourceMatch'] !== $sourceMatchMode) {
            throw new MappingExecutionFailed('Collection mapping dispatch is not an active declared dependency.');
        }

        $dependency = $parentFrame->dependencies[$this->dependencyKey($elementSource, $elementTarget)] ?? null;
        if (null === $dependency) {
            throw new MappingExecutionFailed('Collection mapping dispatch is not an active declared dependency.');
        }

        $definition = $dependency['definition'];
        if (! $definition instanceof MappingDefinition) {
            return $this->mapCustomCollection($mappingExecutionContext, $parentFrame, $definition, $parameter, $values);
        }

        if ($dependency['hasStructuralMappings']) {
            return null;
        }

        $mapper = $dependency['mapper'];
        if (! $mapper instanceof GeneratedMapperInterface) {
            throw new MappingCompilationFailed('Collection mapping dependency has no generated mapper.');
        }

        $mapped = [];
        foreach ($values as $key => $element) {
            if (! is_object($element) || ! SourceMatcher::matches($element, $elementSource, $sourceMatchMode)) {
                $this->collectionElementTypeFailure($source, $target, $parameter, $key, $elementSource, $element);
            }

            // Leaf callbacks must not inherit the parent collection's authority.
            $mappingExecutionContext->currentFrame = null;

            try {
                $result = $mapper->map($element);
            } catch (Throwable) {
                throw $this->executionFailure($definition);
            } finally {
                $mappingExecutionContext->currentFrame = $parentFrame;
            }

            if (! $result instanceof $elementTarget) {
                throw new MappingCompilationFailed('Collection mapping dependency returned an invalid target.');
            }

            $mapped[] = $result;
        }

        return $mapped;
    }

    public function collectionElementTypeFailure(
        string $source,
        string $target,
        string $parameter,
        int|string $key,
        string $expected,
        mixed $actual,
    ): never {
        $context = $this->currentContext();
        $frame   = $context->currentFrame;
        $details = $frame?->collections[$this->collectionKeyId($parameter, $expected)] ?? null;
        if (null === $details
            || $frame->definition->source !== $source
            || $frame->definition->target !== $target) {
            throw new MappingExecutionFailed('Generated collection element validation failed.');
        }

        $context                 = new stdClass();
        $this->weakMap[$context] = [
            ...$details,
            'key'        => $this->collectionKey($key),
            'actualType' => $this->safeType($actual),
            'provenance' => $frame->execution->provenance,
        ];

        throw new GeneratedMappingExecutionFailed($context);
    }

    /** @internal */
    public function collectionFailure(GeneratedMappingExecutionFailed $generatedMappingExecutionFailed, MappingDefinition $mappingDefinition): ?string
    {
        return $this->resolveCollectionFailure($this->currentContext(), $generatedMappingExecutionFailed, $mappingDefinition);
    }

    /** @internal Runs custom roots and children without inheriting generated authority. */
    public function mapCustom(
        CustomMappingDefinition|ProviderCustomMappingDefinition $mappingDefinition,
        object $value,
        CustomMappingExecutor $customMappingExecutor,
    ): object {
        return $this->executeCustom($this->currentContext(), $mappingDefinition, $value, $customMappingExecutor);
    }

    private function resolveCollectionFailure(
        MappingExecutionContext $mappingExecutionContext,
        GeneratedMappingExecutionFailed $generatedMappingExecutionFailed,
        MappingDefinition $mappingDefinition,
    ): ?string {
        $failureContext = $generatedMappingExecutionFailed->context();
        $details        = $this->weakMap[$failureContext] ?? null;
        unset($this->weakMap[$failureContext]);

        if (null === $details || ! $this->isActiveCollectionFailure($mappingExecutionContext, $mappingDefinition, $details)) {
            return null;
        }

        return sprintf(
            'Could not execute collection mapping %s->%s for parameter "%s" at %s: expected %s, got %s.',
            $details['source'],
            $details['target'],
            $details['parameter'],
            $details['key'],
            $details['expected'],
            $details['actualType'],
        );
    }

    /**
     * @param array<int|string, mixed> $values
     *
     * @return list<object>
     */
    private function mapCustomCollection(
        MappingExecutionContext $mappingExecutionContext,
        MappingExecutionFrame $mappingExecutionFrame,
        CustomMappingDefinition|ProviderCustomMappingDefinition $definition,
        string $parameter,
        array $values,
    ): array {
        $elementSource   = $definition->source();
        $elementTarget   = $definition->target();
        $sourceMatchMode = SourceMatcher::modeFor($definition);
        $mapped          = [];
        foreach ($values as $key => $element) {
            if (! is_object($element) || ! SourceMatcher::matches($element, $elementSource, $sourceMatchMode)) {
                $this->collectionElementTypeFailure($mappingExecutionFrame->definition->source, $mappingExecutionFrame->definition->target, $parameter, $key, $elementSource, $element);
            }

            // The compiled binding is already checked; providers still resolve per item.
            $mappingExecutionContext->currentFrame = null;

            try {
                $result = $this->customMappingExecutor->map($definition, $element);
            } finally {
                $mappingExecutionContext->currentFrame = $mappingExecutionFrame;
            }

            if (! $result instanceof $elementTarget) {
                if ($definition instanceof ProviderCustomMappingDefinition) {
                    throw new MappingExecutionFailed(sprintf('Could not execute mapping %s.', $definition->key()), reason: MappingFailureReason::UnexpectedTarget);
                }

                throw new MappingCompilationFailed(sprintf(
                    'Nested mapping %s returned %s instead of an instance of %s.',
                    $definition->key(),
                    $result::class,
                    $elementTarget,
                ));
            }

            $mapped[] = $result;
        }

        return $mapped;
    }

    private function executeCustom(
        MappingExecutionContext $mappingExecutionContext,
        CustomMappingDefinition|ProviderCustomMappingDefinition $mappingDefinition,
        object $value,
        CustomMappingExecutor $customMappingExecutor,
    ): object {
        if (! SourceMatcher::matches($value, $mappingDefinition->source(), SourceMatcher::modeFor($mappingDefinition))) {
            throw new MappingExecutionFailed(sprintf('Could not execute mapping %s.', $mappingDefinition->key()));
        }

        $previous = $mappingExecutionContext->currentFrame;
        // Custom mappings declare no generated dependencies. Hide the parent's
        // authority from both provider resolution and the mapper's callbacks.
        $mappingExecutionContext->currentFrame = null;

        try {
            return $customMappingExecutor->map($mappingDefinition, $value);
        } finally {
            $mappingExecutionContext->currentFrame = $previous;
        }
    }

    /**
     * @param array{source: string, target: string, parameter: string, expected: string, key: string, actualType: string, provenance: object} $details
     */
    private function isActiveCollectionFailure(MappingExecutionContext $mappingExecutionContext, MappingDefinition $mappingDefinition, array $details): bool
    {
        $frame = $mappingExecutionContext->currentFrame;

        return $frame instanceof MappingExecutionFrame
            && $frame->definition->source === $mappingDefinition->source
            && $frame->definition->target === $mappingDefinition->target
            && $frame->execution->provenance === $details['provenance'];
    }

    /** Runs a leaf after its caller has isolated the active scopes. */
    private function executeLeaf(
        MappingDefinition $mappingDefinition,
        object $source,
        GeneratedMapperInterface $generatedMapper,
    ): object {
        try {
            return $generatedMapper->map($source);
        } catch (Throwable) {
            // A leaf cannot issue a structural diagnostic, including a replay.
            throw $this->executionFailure($mappingDefinition);
        }
    }

    private function executeRoot(
        MappingExecutionContext $mappingExecutionContext,
        MappingDefinition $mappingDefinition,
        object $source,
    ): object {
        $preparedMapping = $this->prepare($mappingDefinition, $this->generateOnDemand);

        if (! $preparedMapping['hasStructuralMappings']) {
            return $this->executeLeaf($mappingDefinition, $source, $preparedMapping['mapper']);
        }

        return $this->executeConventional(
            $mappingExecutionContext,
            $mappingDefinition,
            $source,
            $preparedMapping['mapper'],
            $preparedMapping['hasStructuralMappings'],
            $preparedMapping['metadata'],
            new MappingExecution(),
        );
    }

    private function executeConventional(
        MappingExecutionContext $mappingExecutionContext,
        MappingDefinition $mappingDefinition,
        object $source,
        GeneratedMapperInterface $generatedMapper,
        bool $hasStructuralMappings,
        ?MappingMetadata $mappingMetadata = null,
        ?MappingExecution $mappingExecution = null,
    ): object {
        if (! $hasStructuralMappings) {
            $previous                              = $mappingExecutionContext->currentFrame;
            $mappingExecutionContext->currentFrame = null;

            try {
                return $this->executeLeaf($mappingDefinition, $source, $generatedMapper);
            } finally {
                $mappingExecutionContext->currentFrame = $previous;
            }
        }

        $previous                 = $mappingExecutionContext->currentFrame;
        $isRoot                   = ! $previous instanceof MappingExecutionFrame;
        $mappingExecutionFrame    = $this->enterMapping($mappingExecutionContext, $mappingDefinition, $mappingExecution ?? $previous->execution ?? new MappingExecution(), $mappingMetadata);

        try {
            return $generatedMapper->map($source);
        } catch (GeneratedMappingExecutionFailed $exception) {
            if (! $isRoot) {
                throw $exception;
            }

            $collectionFailure = $this->resolveCollectionFailure($mappingExecutionContext, $exception, $mappingDefinition);
            if (null !== $collectionFailure) {
                throw $this->executionFailure($mappingDefinition, $collectionFailure, MappingFailureReason::CollectionElementType);
            }

            throw $this->executionFailure($mappingDefinition);
        } catch (Throwable) {
            throw $this->executionFailure($mappingDefinition);
        } finally {
            $this->exitMapping($mappingExecutionContext, $mappingExecutionFrame);
        }
    }

    private function enterMapping(
        MappingExecutionContext $mappingExecutionContext,
        MappingDefinition $mappingDefinition,
        MappingExecution $mappingExecution,
        ?MappingMetadata $mappingMetadata = null,
    ): MappingExecutionFrame {
        if (! isset($mappingExecution->mappings[$mappingDefinition->key()])) {
            $this->preloadScopeTable($mappingDefinition, $mappingExecution, $mappingMetadata);
        }

        $mappings                 = $this->scopeMappings($mappingDefinition, $mappingExecution, $mappingMetadata);
        $mappingExecutionFrame    = new MappingExecutionFrame(
            $mappingExecutionContext->currentFrame,
            $mappingDefinition,
            $mappingExecution,
            $mappings['dependencies'],
            $mappings['collections'],
        );
        $mappingExecutionContext->currentFrame = $mappingExecutionFrame;

        return $mappingExecutionFrame;
    }

    private function preloadScopeTable(
        MappingDefinition $mappingDefinition,
        MappingExecution $mappingExecution,
        ?MappingMetadata $mappingMetadata,
    ): void {
        if (! $this->reusePreparedMappings || ! $this->mappingRegistry instanceof MappingRegistry) {
            return;
        }

        $scopeTable = $this->preparedScopeTables[$mappingDefinition] ??= $this->buildScopeTable($mappingDefinition, $mappingMetadata);
        if ([] === $mappingExecution->mappings) {
            $mappingExecution->mappings = $scopeTable;

            return;
        }

        $mappingExecution->mappings = $scopeTable + $mappingExecution->mappings;
    }

    /** @return ScopeTable */
    private function buildScopeTable(MappingDefinition $mappingDefinition, ?MappingMetadata $mappingMetadata): array
    {
        $mappingExecution = new MappingExecution();
        $this->scopeMappings($mappingDefinition, $mappingExecution, $mappingMetadata);

        return $mappingExecution->mappings;
    }

    private function exitMapping(MappingExecutionContext $mappingExecutionContext, MappingExecutionFrame $mappingExecutionFrame): void
    {
        $mappingExecutionContext->currentFrame = $mappingExecutionFrame->previous;
    }

    private function currentContext(): MappingExecutionContext
    {
        $fiber = Fiber::getCurrent();

        if (! $fiber instanceof Fiber) {
            return $this->mappingExecutionContext;
        }

        return $this->fiberExecutionContexts[$fiber] ??= new MappingExecutionContext();
    }

    private function executionFailure(MappingDefinition $mappingDefinition, ?string $message = null, ?MappingFailureReason $mappingFailureReason = null): MappingExecutionFailed
    {
        return new MappingExecutionFailed(
            $message ?? sprintf('Could not execute mapping %s.', $mappingDefinition->key()),
            reason: $mappingFailureReason ?? MappingFailureReason::GeneratedMappingFailed,
        );
    }

    /**
     * @param class-string $source
     * @param class-string $target
     *
     * @return null|Dependency
     */
    private function activeDependency(MappingExecutionContext $mappingExecutionContext, string $source, string $target): ?array
    {
        $frame = $mappingExecutionContext->currentFrame;
        if (! $frame instanceof MappingExecutionFrame) {
            return null;
        }

        return $frame->dependencies[$this->dependencyKey($source, $target)] ?? null;
    }

    /** @return array{dependencies: array<string, Dependency>, collections: array<string, CollectionFailureDetails>} */
    private function scopeMappings(
        MappingDefinition $mappingDefinition,
        MappingExecution $mappingExecution,
        ?MappingMetadata $mappingMetadata = null,
    ): array {
        $mappings = $mappingExecution->mappings;
        $key      = $mappingDefinition->key();
        if (isset($mappings[$key])) {
            return $mappings[$key];
        }

        $mappingMetadata ??= $this->metadata($mappingDefinition);
        $dependencies    = [];
        $collections     = [];
        foreach ($mappingMetadata->parameters as $targetParameter) {
            $nested = $targetParameter->nestedMapping;
            if (null === $nested) {
                continue;
            }

            if (! $this->mappingRegistry instanceof MappingRegistryInterface) {
                throw new MappingCompilationFailed(sprintf(
                    'Nested mapping %s -> %s requires a mapping registry.',
                    $nested->source,
                    $nested->target,
                ));
            }

            try {
                $dependency = $this->mappingRegistry->get($nested->source, $nested->target);
                if (! $dependency instanceof MappingDefinition
                    && ! $dependency instanceof CustomMappingDefinition
                    && ! $dependency instanceof ProviderCustomMappingDefinition) {
                    throw new MappingCompilationFailed('Nested mapping dependency does not match its compiled definition.');
                }

                if (! $this->mappingMetadataFactory->matchesCompiledDependency($mappingMetadata, $nested, $dependency)) {
                    throw new MappingCompilationFailed('Nested mapping dependency does not match its compiled definition.');
                }
            } catch (Throwable) {
                throw new MappingCompilationFailed('Nested mapping dependency does not match its compiled definition.');
            }

            $mapper                = null;
            $hasStructuralMappings = false;
            if ($dependency instanceof MappingDefinition) {
                $dependencyMetadata = $this->mappingMetadataFactory->compiledDependencyMetadata($mappingMetadata, $nested);
                if (! $dependencyMetadata instanceof MappingMetadata) {
                    throw new MappingCompilationFailed('Nested mapping dependency does not match its compiled definition.');
                }

                $preparedMapping       = $this->prepare($dependency, $this->generateOnDemand, $dependencyMetadata);
                $mapper                = $preparedMapping['mapper'];
                $hasStructuralMappings = $preparedMapping['hasStructuralMappings'];
                if ($hasStructuralMappings) {
                    $this->scopeMappings($dependency, $mappingExecution, $preparedMapping['metadata']);
                }
            }

            $dependencies[$this->dependencyKey($nested->source, $nested->target)] = [
                'definition'            => $dependency,
                'mapper'                => $mapper,
                'hasStructuralMappings' => $hasStructuralMappings,
            ];

            if ('collection' === $nested->operation && null !== $nested->elementSource) {
                $collections[$this->collectionKeyId($targetParameter->name, $nested->elementSource)] = [
                    'source'        => $mappingMetadata->source,
                    'target'        => $mappingMetadata->target,
                    'parameter'     => $targetParameter->name,
                    'expected'      => $nested->elementSource,
                    'elementTarget' => $nested->target,
                    'sourceMatch'   => $nested->sourceMatch,
                ];
            }
        }

        // Recursive preparation may have populated descendants while this scope
        // was being assembled. Merge with that current snapshot rather than
        // overwriting it with the caller's stale local copy.
        $mappings       = $mappingExecution->mappings ?: $mappings;
        $mappings[$key] = [
            'dependencies' => $dependencies,
            'collections'  => $collections,
        ];
        $mappingExecution->mappings = $mappings;

        return $mappings[$key];
    }

    /** @param class-string $source @param class-string $target */
    private function dependencyKey(string $source, string $target): string
    {
        return $source . "\0" . $target;
    }

    private function collectionKeyId(string $parameter, string $expected): string
    {
        return $parameter . "\0" . $expected;
    }

    private function safeType(mixed $value): string
    {
        if (is_object($value)) {
            return 1 === preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\\\[a-zA-Z_][a-zA-Z0-9_]*)*$/D', $value::class)
                ? $value::class
                : 'object';
        }

        return match (true) {
            is_null($value)   => 'null',
            is_bool($value)   => 'bool',
            is_int($value)    => 'int',
            is_float($value)  => 'float',
            is_string($value) => 'string',
            is_array($value)  => 'array',
            default           => 'non-object',
        };
    }

    private function collectionKey(int|string $key): string
    {
        if (is_int($key)) {
            return sprintf('integer key %d', $key);
        }

        return sprintf('string key sha256:%s (length %d)', substr(hash('sha256', $key), 0, 16), strlen($key));
    }

    /**
     * @return PreparedMapping
     */
    private function prepare(
        MappingDefinition $mappingDefinition,
        bool $allowGeneration,
        ?MappingMetadata $mappingMetadata = null,
    ): array {
        if ($this->reusePreparedMappings && isset($this->preparedMappings[$mappingDefinition])) {
            return $this->preparedMappings[$mappingDefinition];
        }

        $mappingMetadata ??= $this->mappingMetadataFactory->create($mappingDefinition);
        $cacheKey         = $this->phpMapperGenerator->cacheKey($mappingMetadata);
        $preparedMapping  = [
            'metadata'              => $mappingMetadata,
            'cacheKey'              => $cacheKey,
            'mapper'                => $this->resolve($mappingDefinition, $allowGeneration, $mappingMetadata, $cacheKey),
            'hasStructuralMappings' => $this->hasStructuralMappings($mappingMetadata),
        ];
        if ($this->reusePreparedMappings) {
            $this->preparedMappings[$mappingDefinition] = $preparedMapping;
        }

        return $preparedMapping;
    }

    private function hasStructuralMappings(MappingMetadata $mappingMetadata): bool
    {
        foreach ($mappingMetadata->parameters as $targetParameter) {
            if (null !== $targetParameter->nestedMapping) {
                return true;
            }
        }

        return false;
    }

    private function resolve(
        MappingDefinition $mappingDefinition,
        bool $allowGeneration,
        MappingMetadata $mappingMetadata,
        string $key,
    ): GeneratedMapperInterface {
        if (isset($this->mappers[$key])) {
            return $this->mappers[$key];
        }

        $className = $this->phpMapperGenerator->className($key);
        $content   = $this->phpMapperGenerator->generate($mappingMetadata, $key);
        if ($allowGeneration) {
            $this->ensureCacheDirectory();
        } elseif (! is_dir($this->cacheDirectory)) {
            throw new MappingCompilationFailed(sprintf(
                'Generated mapper cache miss for %s. Warm the cache before production use.',
                $mappingDefinition->key(),
            ));
        }

        $this->assertSafeCacheDirectory();
        $path     = $this->cachePath($key);
        $lockPath = $this->lockPath($key);
        if (is_link($lockPath)) {
            throw new MappingCompilationFailed(sprintf('Mapper cache lock %s must not be a symbolic link.', $lockPath));
        }

        $lock = fopen($lockPath, $allowGeneration ? 'c' : 'r');
        if (false === $lock) {
            throw new MappingCompilationFailed(sprintf(
                'Could not open mapper cache lock for %s. Warm the cache before production use.',
                $mappingDefinition->key(),
            ));
        }

        try {
            if (! flock($lock, $allowGeneration ? LOCK_EX : LOCK_SH)) {
                throw new MappingCompilationFailed(sprintf('Could not lock mapper cache for %s.', $mappingDefinition->key()));
            }

            if (is_link($path)) {
                throw new MappingCompilationFailed(sprintf('Generated mapper file %s must not be a symbolic link.', $path));
            }

            if (is_file($path)) {
                return $this->remember(
                    $key,
                    $this->load($path, $className, $content),
                );
            }

            if (! $allowGeneration) {
                throw new MappingCompilationFailed(sprintf(
                    'Generated mapper cache miss for %s. Warm the cache before production use.',
                    $mappingDefinition->key(),
                ));
            }

            if (! is_file($path)) {
                $this->write($path, $content, $mappingDefinition);
            }

            return $this->remember(
                $key,
                $this->load($path, $className, $content),
            );
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function write(string $path, string $content, MappingDefinition $mappingDefinition): void
    {
        $temporaryPath = tempnam($this->cacheDirectory, '.mapper-');
        if (false === $temporaryPath) {
            throw new MappingCompilationFailed(sprintf('Could not create temporary mapper file for %s.', $mappingDefinition->key()));
        }

        try {
            if (false === file_put_contents($temporaryPath, $content, LOCK_EX)) {
                throw new MappingCompilationFailed(sprintf('Could not write generated mapper for %s.', $mappingDefinition->key()));
            }

            if (! chmod($temporaryPath, 0o600)) {
                throw new MappingCompilationFailed(sprintf('Could not secure generated mapper for %s.', $mappingDefinition->key()));
            }

            $this->lint($temporaryPath, $mappingDefinition);
            if (! rename($temporaryPath, $path)) {
                throw new MappingCompilationFailed(sprintf('Could not atomically publish generated mapper for %s.', $mappingDefinition->key()));
            }

            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($path, true);
            }
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    private function remember(
        string $cacheKey,
        GeneratedMapperInterface $generatedMapper,
    ): GeneratedMapperInterface {
        $this->mappers[$cacheKey] = $generatedMapper;

        return $generatedMapper;
    }

    private function lint(string $path, MappingDefinition $mappingDefinition): void
    {
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path), $output, $status);
        if (0 !== $status) {
            throw new MappingCompilationFailed(sprintf(
                'Generated mapper for %s did not pass PHP lint: %s',
                $mappingDefinition->key(),
                implode("\n", $output),
            ));
        }
    }

    private function load(string $path, string $className, string $expectedContent): GeneratedMapperInterface
    {
        $this->assertSafeCacheFile($path);
        $actualContent = file_get_contents($path);
        if (false === $actualContent || ! hash_equals($expectedContent, $actualContent)) {
            throw new MappingCompilationFailed(sprintf('Generated mapper file %s is unreadable or has changed.', $path));
        }

        if (! class_exists($className, false)) {
            try {
                require_once $path;
            } catch (Throwable $exception) {
                throw new MappingCompilationFailed(sprintf('Could not load generated mapper file %s.', $path), $exception->getCode(), previous: $exception);
            }
        }

        if (! class_exists($className, false)) {
            throw new MappingCompilationFailed(sprintf('Generated mapper file %s did not define %s.', $path, $className));
        }

        $mapper = new $className($this->valueTransformerRegistry, $this);
        if (! $mapper instanceof GeneratedMapperInterface) {
            throw new MappingCompilationFailed(sprintf('Generated mapper class %s has an invalid type.', $className));
        }

        return $mapper;
    }

    private function ensureCacheDirectory(): void
    {
        if (! is_dir($this->cacheDirectory) && ! mkdir($concurrentDirectory = $this->cacheDirectory, 0o700, true) && ! is_dir($concurrentDirectory)) {
            throw new MappingCompilationFailed(sprintf('Could not create mapper cache directory %s.', $this->cacheDirectory));
        }

        if (! is_writable($this->cacheDirectory)) {
            throw new MappingCompilationFailed(sprintf('Mapper cache directory %s is not writable.', $this->cacheDirectory));
        }
    }

    private function assertSafeCacheDirectory(): void
    {
        if (is_link($this->cacheDirectory)) {
            throw new MappingCompilationFailed(sprintf('Mapper cache directory %s must not be a symbolic link.', $this->cacheDirectory));
        }

        $mode = fileperms($this->cacheDirectory);
        if (false === $mode || 0 !== ($mode & 0o077)) {
            throw new MappingCompilationFailed(sprintf(
                'Mapper cache directory %s must be accessible only by its owner (0700).',
                $this->cacheDirectory,
            ));
        }
    }

    private function assertSafeCacheFile(string $path): void
    {
        if (is_link($path)) {
            throw new MappingCompilationFailed(sprintf('Generated mapper file %s must not be a symbolic link.', $path));
        }

        $mode = fileperms($path);
        if (false === $mode || 0 !== ($mode & 0o077)) {
            throw new MappingCompilationFailed(sprintf(
                'Generated mapper file %s must be accessible only by its owner (0600).',
                $path,
            ));
        }
    }

    private function cachePath(string $key): string
    {
        return $this->cacheDirectory . DIRECTORY_SEPARATOR . 'Mapper_' . $key . '.php';
    }

    private function lockPath(string $key): string
    {
        return $this->cacheDirectory . DIRECTORY_SEPARATOR . '.Mapper_' . $key . '.lock';
    }
}
