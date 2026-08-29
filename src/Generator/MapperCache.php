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
use Sirix\ObjectMapper\Exception\MappingCompilationFailed;
use Sirix\ObjectMapper\Exception\MappingExecutionFailed;
use Sirix\ObjectMapper\Metadata\MappingMetadata;
use Sirix\ObjectMapper\Metadata\MappingMetadataFactory;
use Sirix\ObjectMapper\Runtime\CustomMappingExecutor;
use Sirix\ObjectMapper\Runtime\GeneratedMappingExecutionFailed;
use Sirix\ObjectMapper\Runtime\NestedMappingRuntimeInterface;
use stdClass;
use Throwable;

use WeakMap;

use function array_key_last;
use function array_pop;
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
 * @phpstan-type Dependency array{definition: CustomMappingDefinition|MappingDefinition|ProviderCustomMappingDefinition, mapper: GeneratedMapperInterface|null}
 * @phpstan-type CollectionFailureDetails array{source: string, target: string, parameter: string, expected: string}
 * @phpstan-type Scope array{definition: MappingDefinition, source: string, target: string, execution: object, dependencies: array<string, Dependency>, collections: array<string, CollectionFailureDetails>}
 */
final class MapperCache implements NestedMappingRuntimeInterface
{
    /** @var array<string, GeneratedMapperInterface> */
    private array $mappers = [];

    /** @var WeakMap<object, array{source: string, target: string, parameter: string, expected: string, key: string, actualType: string, execution: object}> */
    private readonly WeakMap $weakMap;

    /** @var list<Scope> */
    private array $mainMappingScopes = [];

    /** @var WeakMap<object, list<Scope>> */
    private readonly WeakMap $fiberScopes;

    /** @var WeakMap<object, array<string, array{dependencies: array<string, Dependency>, collections: array<string, CollectionFailureDetails>}>> */
    private readonly WeakMap $executionMappings;

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
    ) {
        if ('' === $cacheDirectory) {
            throw new InvalidArgumentException('The mapper cache directory cannot be empty.');
        }

        $this->weakMap               = new WeakMap();
        $this->fiberScopes           = new WeakMap();
        $this->executionMappings     = new WeakMap();
        $this->customMappingExecutor = $customMappingExecutor ?? new CustomMappingExecutor($customObjectMapperProvider);
    }

    public function get(MappingDefinition $mappingDefinition): GeneratedMapperInterface
    {
        $mappingMetadata = $this->metadata($mappingDefinition);

        return $this->resolve($mappingDefinition, $this->generateOnDemand, $mappingMetadata);
    }

    public function map(MappingDefinition $mappingDefinition, object $source): object
    {
        $scopes = $this->scopes();
        $this->saveScopes([]);

        try {
            $mappingMetadata = $this->metadata($mappingDefinition);

            return $this->executeConventional(
                $mappingDefinition,
                $source,
                $this->resolve($mappingDefinition, $this->generateOnDemand, $mappingMetadata),
                $mappingMetadata,
            );
        } finally {
            $this->saveScopes($scopes);
        }
    }

    public function warm(MappingDefinition $mappingDefinition, ?MappingMetadata $mappingMetadata = null): GeneratedMapperInterface
    {
        return $this->resolve($mappingDefinition, true, $mappingMetadata ?? $this->metadata($mappingDefinition));
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
     * @param class-string $source
     * @param class-string $target
     */
    public function mapNested(object $value, string $source, string $target): object
    {
        if (! $this->mappingRegistry instanceof MappingRegistryInterface) {
            throw new MappingCompilationFailed(sprintf(
                'Nested mapping %s -> %s requires a mapping registry.',
                $source,
                $target,
            ));
        }

        if ($value::class !== $source) {
            throw new MappingExecutionFailed(sprintf(
                'Nested mapping %s -> %s expected an exact %s source object.',
                $source,
                $target,
                $source,
            ));
        }

        $dependency = $this->activeDependency($source, $target);
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

            $mapped = $this->executeConventional($mappingDefinition, $value, $generatedMapper);
        } elseif ($mappingDefinition instanceof CustomMappingDefinition) {
            $mapped = $this->customMappingExecutor->map($mappingDefinition, $value);
        } elseif ($mappingDefinition instanceof ProviderCustomMappingDefinition) {
            $mapped = $this->customMappingExecutor->map($mappingDefinition, $value);
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
                ));
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

    public function collectionElementTypeFailure(
        string $source,
        string $target,
        string $parameter,
        int|string $key,
        string $expected,
        mixed $actual,
    ): never {
        $scope   = $this->activeScope();
        $details = null === $scope ? null : ($scope['collections'][$this->collectionKeyId($parameter, $expected)] ?? null);
        if (null === $details
            || $scope['source'] !== $source
            || $scope['target'] !== $target) {
            throw new MappingExecutionFailed('Generated collection element validation failed.');
        }

        $context                 = new stdClass();
        $this->weakMap[$context] = [
            ...$details,
            'key'        => $this->collectionKey($key),
            'actualType' => $this->safeType($actual),
            'execution'  => $scope['execution'],
        ];

        throw new GeneratedMappingExecutionFailed($context);
    }

    /** @internal */
    public function collectionFailure(GeneratedMappingExecutionFailed $generatedMappingExecutionFailed, MappingDefinition $mappingDefinition): ?string
    {
        $context = $generatedMappingExecutionFailed->context();
        $details = $this->weakMap[$context] ?? null;
        unset($this->weakMap[$context]);

        if (null === $details || ! $this->isActiveCollectionFailure($mappingDefinition, $details)) {
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
     * @param array{source: string, target: string, parameter: string, expected: string, key: string, actualType: string, execution: object} $details
     */
    private function isActiveCollectionFailure(MappingDefinition $mappingDefinition, array $details): bool
    {
        $scope = $this->activeScope();

        return null !== $scope
            && $scope['source'] === $mappingDefinition->source
            && $scope['target'] === $mappingDefinition->target
            && $scope['execution'] === $details['execution'];
    }

    private function executeConventional(
        MappingDefinition $mappingDefinition,
        object $source,
        GeneratedMapperInterface $generatedMapper,
        ?MappingMetadata $mappingMetadata = null,
    ): object {
        $isRoot = null === $this->activeScope();
        $this->pushMapping($mappingDefinition, $mappingMetadata);

        try {
            return $generatedMapper->map($source);
        } catch (GeneratedMappingExecutionFailed $exception) {
            if (! $isRoot) {
                throw $exception;
            }

            $collectionFailure = $this->collectionFailure($exception, $mappingDefinition);
            if (null !== $collectionFailure) {
                throw $this->executionFailure($mappingDefinition, $collectionFailure);
            }

            throw $this->executionFailure($mappingDefinition);
        } catch (Throwable) {
            throw new MappingExecutionFailed(sprintf('Could not execute mapping %s.', $mappingDefinition->key()));
        } finally {
            $this->popMapping();
        }
    }

    private function pushMapping(MappingDefinition $mappingDefinition, ?MappingMetadata $mappingMetadata = null): void
    {
        $scopes    = $this->scopes();
        $scope     = [] === $scopes ? null : $scopes[array_key_last($scopes)];
        $execution = $scope['execution'] ?? new stdClass();
        $scopes[]  = [
            'definition' => $mappingDefinition,
            'source'     => $mappingDefinition->source,
            'target'     => $mappingDefinition->target,
            'execution'  => $execution,
            ...$this->scopeMappings($mappingDefinition, $execution, $mappingMetadata),
        ];
        $this->saveScopes($scopes);
    }

    private function popMapping(): void
    {
        $scopes = $this->scopes();
        array_pop($scopes);
        $this->saveScopes($scopes);
    }

    /** @return list<Scope> */
    private function scopes(): array
    {
        $fiber = Fiber::getCurrent();

        return $fiber instanceof Fiber ? $this->fiberScopes[$fiber] ?? [] : ($this->mainMappingScopes);
    }

    /** @param list<Scope> $scopes */
    private function saveScopes(array $scopes): void
    {
        $fiber = Fiber::getCurrent();
        if (! $fiber instanceof Fiber) {
            $this->mainMappingScopes = $scopes;

            return;
        }

        $this->fiberScopes[$fiber] = $scopes;
    }

    private function executionFailure(MappingDefinition $mappingDefinition, ?string $message = null): MappingExecutionFailed
    {
        return new MappingExecutionFailed($message ?? sprintf('Could not execute mapping %s.', $mappingDefinition->key()));
    }

    /** @return null|Scope */
    private function activeScope(): ?array
    {
        $scopes = $this->scopes();

        return [] === $scopes ? null : $scopes[array_key_last($scopes)];
    }

    /**
     * @param class-string $source
     * @param class-string $target
     *
     * @return null|Dependency
     */
    private function activeDependency(string $source, string $target): ?array
    {
        $scope = $this->activeScope();
        if (null === $scope) {
            return null;
        }

        return $scope['dependencies'][$this->dependencyKey($source, $target)] ?? null;
    }

    /** @return array{dependencies: array<string, Dependency>, collections: array<string, CollectionFailureDetails>} */
    private function scopeMappings(
        MappingDefinition $mappingDefinition,
        object $execution,
        ?MappingMetadata $mappingMetadata = null,
    ): array {
        $mappings = $this->executionMappings[$execution] ?? [];
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

            $mapper = null;
            if ($dependency instanceof MappingDefinition) {
                $dependencyMetadata = $this->mappingMetadataFactory->compiledDependencyMetadata($mappingMetadata, $nested);
                if (! $dependencyMetadata instanceof MappingMetadata) {
                    throw new MappingCompilationFailed('Nested mapping dependency does not match its compiled definition.');
                }

                $mapper             = $this->resolve($dependency, $this->generateOnDemand, $dependencyMetadata);
                $this->scopeMappings($dependency, $execution, $dependencyMetadata);
            }

            $dependencies[$this->dependencyKey($nested->source, $nested->target)] = [
                'definition' => $dependency,
                'mapper'     => $mapper,
            ];

            if ('collection' === $nested->operation && null !== $nested->elementSource) {
                $collections[$this->collectionKeyId($targetParameter->name, $nested->elementSource)] = [
                    'source'    => $mappingMetadata->source,
                    'target'    => $mappingMetadata->target,
                    'parameter' => $targetParameter->name,
                    'expected'  => $nested->elementSource,
                ];
            }
        }

        // Recursive preparation may have populated descendants while this scope
        // was being assembled. Merge with that current snapshot rather than
        // overwriting it with the caller's stale local copy.
        $mappings       = $this->executionMappings[$execution] ?? $mappings;
        $mappings[$key] = [
            'dependencies' => $dependencies,
            'collections'  => $collections,
        ];
        $this->executionMappings[$execution] = $mappings;

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

    private function resolve(
        MappingDefinition $mappingDefinition,
        bool $allowGeneration,
        ?MappingMetadata $mappingMetadata = null,
    ): GeneratedMapperInterface {
        $mappingMetadata ??= $this->mappingMetadataFactory->create($mappingDefinition);
        $key             = $this->phpMapperGenerator->cacheKey($mappingMetadata);
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
