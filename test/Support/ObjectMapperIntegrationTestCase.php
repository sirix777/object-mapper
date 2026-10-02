<?php

declare(strict_types=1);

namespace Sirix\ObjectMapperTest\Integration;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Sirix\ObjectMapper\Contract\CustomObjectMapperProviderInterface;
use Sirix\ObjectMapper\Contract\MappingDefinitionInterface;
use Sirix\ObjectMapper\Contract\MappingRegistryInterface;
use Sirix\ObjectMapper\Definition\CustomMappingDefinition;
use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Definition\MapRule;
use Sirix\ObjectMapper\Definition\ProviderCustomMappingDefinition;
use Sirix\ObjectMapper\Definition\SourceMatchMode;
use Sirix\ObjectMapper\Exception\MappingCompilationFailed;
use Sirix\ObjectMapper\Exception\MappingExecutionFailed;
use Sirix\ObjectMapper\Generator\MapperCache;
use Sirix\ObjectMapper\Generator\PhpMapperGenerator;
use Sirix\ObjectMapper\Metadata\MappingMetadataFactory;
use Sirix\ObjectMapper\Runtime\CustomMappingExecutor;
use Sirix\ObjectMapper\Runtime\MappingExecution;
use Sirix\ObjectMapper\Runtime\MappingExecutionFrame;
use Sirix\ObjectMapper\Runtime\MappingRegistry;
use Sirix\ObjectMapper\Runtime\ObjectMapper;
use Sirix\ObjectMapper\Runtime\ValueTransformerRegistry;
use Sirix\ObjectMapperTest\Support\CallbackLeafDto;
use Sirix\ObjectMapperTest\Support\CallbackLeafHolderDto;
use Sirix\ObjectMapperTest\Support\CallbackLeafHolderSource;
use Sirix\ObjectMapperTest\Support\CallbackLeafSource;
use Sirix\ObjectMapperTest\Support\CallbackLeafTransformer;
use Sirix\ObjectMapperTest\Support\CallbackRelease;
use Sirix\ObjectMapperTest\Support\CallbackReleaseMapper;
use Sirix\ObjectMapperTest\Support\CallbackReleaseProvider;
use Sirix\ObjectMapperTest\Support\ConstructorOrderSource;
use Sirix\ObjectMapperTest\Support\ConstructorOrderTarget;
use Sirix\ObjectMapperTest\Support\ConventionalSource;
use Sirix\ObjectMapperTest\Support\ConventionalTarget;
use Sirix\ObjectMapperTest\Support\ObservedReleaseCollectionsDto;
use Sirix\ObjectMapperTest\Support\ObservedReleaseCollectionsSource;
use Sirix\ObjectMapperTest\Support\Release;
use Sirix\ObjectMapperTest\Support\ReleaseDto;

use function bin2hex;
use function class_alias;
use function class_exists;
use function is_array;
use function is_dir;
use function random_bytes;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function unlink;

abstract class ObjectMapperIntegrationTestCase extends TestCase
{
    protected string $cacheDirectory;

    protected function setUp(): void
    {
        $this->cacheDirectory = sys_get_temp_dir() . '/object-mapper-test-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (! is_dir($this->cacheDirectory)) {
            return;
        }

        foreach (scandir($this->cacheDirectory) ?: [] as $file) {
            if ('.' !== $file && '..' !== $file) {
                unlink($this->cacheDirectory . '/' . $file);
            }
        }

        if (is_dir($this->cacheDirectory)) {
            rmdir($this->cacheDirectory);
        }
    }

    /** @return iterable<string, array{bool, string}> */
    public static function suspendedNestedLeafCallbackModes(): iterable
    {
        foreach (self::collectionCacheModes() as $mode => [$prepared]) {
            foreach (['getter', 'transformer'] as $callback) {
                yield $mode . '-' . $callback => [$prepared, $callback];
            }
        }
    }

    /** @return iterable<string, array{bool, string, bool, bool}> */
    public static function leafReplayModes(): iterable
    {
        foreach (self::leafReentryModes() as $name => [$prepared, $callback, $nested]) {
            foreach ([true, false] as $consume) {
                yield $name . ($consume ? '-consumed' : '-unconsumed') => [$prepared, $callback, $nested, $consume];
            }
        }
    }

    /** @return array<string, array{bool}> */
    public static function collectionCacheModes(): array
    {
        return [
            'default'  => [false],
            'prepared' => [true],
        ];
    }

    /** @return iterable<string, array{bool, string, bool}> */
    public static function leafReentryModes(): iterable
    {
        foreach (self::collectionCacheModes() as $mode => [$prepared]) {
            foreach (['getter', 'transformer', 'constructor'] as $callback) {
                foreach ([false, true] as $nested) {
                    yield $mode . '-' . $callback . ($nested ? '-nested' : '-root') => [$prepared, $callback, $nested];
                }
            }
        }
    }

    protected static function assertLeafCannotBorrowParentContext(MapperCache $mapperCache): void
    {
        foreach (['nested', 'collection', 'failure'] as $attempt) {
            $expectedMessage = match ($attempt) {
                'nested'     => 'Nested mapping dispatch is not an active declared dependency.',
                'collection' => 'Collection mapping dispatch is not an active declared dependency.',
                'failure'    => 'Generated collection element validation failed.',
            };

            try {
                match ($attempt) {
                    'nested'     => $mapperCache->mapNested(new Release('sibling'), Release::class, ReleaseDto::class, SourceMatchMode::Exact),
                    'collection' => $mapperCache->mapCollection([], CallbackLeafHolderSource::class, CallbackLeafHolderDto::class, 'releases', Release::class, ReleaseDto::class, SourceMatchMode::Exact),
                    'failure'    => $mapperCache->collectionElementTypeFailure(CallbackLeafHolderSource::class, CallbackLeafHolderDto::class, 'releases', 'sensitive-parent-key', Release::class, null),
                };
                self::fail('A leaf borrowed parent context: ' . $attempt);
            } catch (MappingExecutionFailed $exception) {
                self::assertSame($expectedMessage, $exception->getMessage());
                self::assertNull($exception->getPrevious());
            }
        }
    }

    /** @param class-string<CallbackLeafDto> $target */
    protected function leafCallbackParent(ObjectMapper $objectMapper, CallbackLeafSource $callbackLeafSource, string $target, bool $nested): CallbackLeafHolderSource
    {
        return new CallbackLeafHolderSource($nested ? $callbackLeafSource : null, [new Release('sibling')], $nested ? null : static function() use ($objectMapper, $callbackLeafSource, $target): void {
            self::assertSame('leaf', $objectMapper->map($callbackLeafSource, $target)->version);
        });
    }

    /** @return array{ObjectMapper, MapperCache, class-string<CallbackLeafDto>} */
    protected function leafCallbackRuntime(bool $prepared, string $callbackKind): array
    {
        $target                   = CallbackLeafDto::class;
        $valueTransformerRegistry = new ValueTransformerRegistry([new CallbackLeafTransformer()]);
        $mappingRegistry          = new MappingRegistry([
            new MappingDefinition(CallbackLeafSource::class, $target, [
                'version' => match ($callbackKind) {
                    'getter'      => MapRule::fromGetter('getVersion'),
                    'transformer' => MapRule::fromGetter('getCallback')->through(CallbackLeafTransformer::class),
                    'constructor' => MapRule::fromGetter('getCallback'),
                    default       => throw new InvalidArgumentException('Unknown leaf callback kind: ' . $callbackKind),
                },
            ]),
            new MappingDefinition(Release::class, ReleaseDto::class),
            new MappingDefinition(CallbackLeafHolderSource::class, CallbackLeafHolderDto::class, [
                'leaf'     => MapRule::fromGetter('getLeaf')->nested($target),
                'releases' => MapRule::fromGetter('getReleases')->collection(Release::class, ReleaseDto::class),
            ]),
            new MappingDefinition(FiberCollectionFailureSource::class, FiberCollectionFailureDto::class, [
                'releases' => MapRule::fromGetter('getReleases')->collection(Release::class, ReleaseDto::class),
            ]),
        ]);
        $mapperCache = new MapperCache(
            new MappingMetadataFactory($valueTransformerRegistry, mappingRegistry: $mappingRegistry),
            new PhpMapperGenerator(), $this->cacheDirectory, $valueTransformerRegistry,
            generateOnDemand: true, mappingRegistry: $mappingRegistry, reusePreparedMappings: $prepared,
        );

        return [new ObjectMapper($mappingRegistry, $mapperCache), $mapperCache, $target];
    }

    protected function mapper(bool $generateOnDemand, MappingDefinitionInterface ...$definitions): ObjectMapper
    {
        $valueTransformerRegistry = new ValueTransformerRegistry();
        $mappingRegistry          = new MappingRegistry($definitions);

        return new ObjectMapper(
            $mappingRegistry,
            new MapperCache(
                new MappingMetadataFactory($valueTransformerRegistry, mappingRegistry: $mappingRegistry),
                new PhpMapperGenerator(),
                $this->cacheDirectory,
                $valueTransformerRegistry,
                $generateOnDemand,
                $mappingRegistry,
            ),
        );
    }

    /** @return array{ObjectMapper, MapperCache, MappingMetadataFactory} */
    protected function fixedPreparedRuntime(MappingDefinition ...$definitions): array
    {
        $mappingRegistry          = new MappingRegistry($definitions);
        $valueTransformerRegistry = new ValueTransformerRegistry();
        $mappingMetadataFactory   = new MappingMetadataFactory($valueTransformerRegistry, mappingRegistry: $mappingRegistry);
        $mapperCache              = new MapperCache(
            $mappingMetadataFactory,
            new PhpMapperGenerator(),
            $this->cacheDirectory,
            $valueTransformerRegistry,
            true,
            $mappingRegistry,
            reusePreparedMappings: true,
        );

        return [new ObjectMapper($mappingRegistry, $mapperCache), $mapperCache, $mappingMetadataFactory];
    }

    protected function mapperWithRegistry(MappingRegistryInterface $mappingRegistry): ObjectMapper
    {
        $valueTransformerRegistry = new ValueTransformerRegistry();

        return new ObjectMapper(
            $mappingRegistry,
            new MapperCache(
                new MappingMetadataFactory($valueTransformerRegistry, mappingRegistry: $mappingRegistry),
                new PhpMapperGenerator(),
                $this->cacheDirectory,
                $valueTransformerRegistry,
                true,
                $mappingRegistry,
                reusePreparedMappings: true,
            ),
        );
    }

    /** @param class-string $target */
    protected function assertMapRejected(ObjectMapper $objectMapper, object $source, string $target = ExactHolderDto::class): void
    {
        try {
            $objectMapper->map($source, $target);
            self::fail('Expected the swapped dependency to be rejected.');
        } catch (MappingCompilationFailed) {
            self::addToAssertionCount(1);
        }
    }

    protected function containsExecutionState(mixed $value): bool
    {
        if ($value instanceof MappingExecution || $value instanceof MappingExecutionFrame) {
            return true;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->containsExecutionState($item)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function orderMapper(): ObjectMapper
    {
        return $this->mapper(
            true,
            new MappingDefinition(Release::class, ReleaseDto::class),
            new MappingDefinition(ConstructorOrderSource::class, ConstructorOrderTarget::class, [
                'child' => MapRule::fromGetter('getChild')->nested(ReleaseDto::class),
                'items' => MapRule::fromGetter('getItems')->collection(Release::class, ReleaseDto::class),
            ]),
        );
    }

    /** @return array{ObjectMapper, MapperCache, MappingRegistry} */
    protected function collectionCallbackRuntime(?CallbackReleaseMapper $callbackReleaseMapper = null, ?CallbackReleaseProvider $callbackReleaseProvider = null, bool $reusePreparedMappings = false): array
    {
        $mappingRegistry = new MappingRegistry([
            $callbackReleaseProvider instanceof CallbackReleaseProvider
                ? new ProviderCustomMappingDefinition(CallbackRelease::class, ReleaseDto::class, 'child')
                : ($callbackReleaseMapper instanceof CallbackReleaseMapper
                    ? new CustomMappingDefinition(CallbackRelease::class, ReleaseDto::class, $callbackReleaseMapper)
                    : new MappingDefinition(CallbackRelease::class, ReleaseDto::class)),
            new MappingDefinition(Release::class, ReleaseDto::class),
            new MappingDefinition(ObservedReleaseCollectionsSource::class, ObservedReleaseCollectionsDto::class, [
                'first'  => MapRule::fromGetter('getFirst')->collection(CallbackRelease::class, ReleaseDto::class),
                'second' => MapRule::fromGetter('getSecond')->collection(Release::class, ReleaseDto::class),
            ]),
        ]);
        $mapperCache = new MapperCache(
            new MappingMetadataFactory(mappingRegistry: $mappingRegistry),
            new PhpMapperGenerator(), $this->cacheDirectory, new ValueTransformerRegistry(),
            generateOnDemand: true, mappingRegistry: $mappingRegistry, customObjectMapperProvider: $callbackReleaseProvider, reusePreparedMappings: $reusePreparedMappings,
        );

        return [new ObjectMapper($mappingRegistry, $mapperCache), $mapperCache, $mappingRegistry];
    }

    protected function mapperWithPreparedCache(
        bool $generateOnDemand,
        bool $reusePreparedMappings,
        MappingDefinitionInterface ...$definitions,
    ): ObjectMapper {
        $valueTransformerRegistry = new ValueTransformerRegistry();
        $mappingRegistry          = new MappingRegistry($definitions);

        return new ObjectMapper(
            $mappingRegistry,
            new MapperCache(
                new MappingMetadataFactory($valueTransformerRegistry, mappingRegistry: $mappingRegistry),
                new PhpMapperGenerator(),
                $this->cacheDirectory,
                $valueTransformerRegistry,
                $generateOnDemand,
                $mappingRegistry,
                reusePreparedMappings: $reusePreparedMappings,
            ),
        );
    }

    /**
     * @return array{ObjectMapper, CountingValueTransformerRegistry}
     */
    protected function mapperWithCountingTransformer(bool $reusePreparedMappings): array
    {
        $mappingDefinition = new MappingDefinition(ConventionalSource::class, ConventionalTarget::class, [
            'name' => MapRule::from('name')->through(PreparedCacheCountingTransformer::class),
        ]);
        $mappingRegistry                  = new MappingRegistry([$mappingDefinition]);
        $countingValueTransformerRegistry = new CountingValueTransformerRegistry(new PreparedCacheCountingTransformer());

        return [
            new ObjectMapper(
                $mappingRegistry,
                new MapperCache(
                    new MappingMetadataFactory($countingValueTransformerRegistry, mappingRegistry: $mappingRegistry),
                    new PhpMapperGenerator(),
                    $this->cacheDirectory,
                    $countingValueTransformerRegistry,
                    false,
                    $mappingRegistry,
                    reusePreparedMappings: $reusePreparedMappings,
                ),
            ),
            $countingValueTransformerRegistry,
        ];
    }

    protected function mapperWithPreparedCacheAndProvider(
        bool $generateOnDemand,
        CustomObjectMapperProviderInterface $customObjectMapperProvider,
        MappingDefinitionInterface ...$definitions,
    ): ObjectMapper {
        $valueTransformerRegistry = new ValueTransformerRegistry();
        $mappingRegistry          = new MappingRegistry($definitions);
        $customMappingExecutor    = new CustomMappingExecutor($customObjectMapperProvider);

        return new ObjectMapper(
            $mappingRegistry,
            new MapperCache(
                new MappingMetadataFactory($valueTransformerRegistry, mappingRegistry: $mappingRegistry),
                new PhpMapperGenerator(),
                $this->cacheDirectory,
                $valueTransformerRegistry,
                $generateOnDemand,
                $mappingRegistry,
                $customObjectMapperProvider,
                $customMappingExecutor,
                true,
            ),
            $customObjectMapperProvider,
            $customMappingExecutor,
        );
    }

    protected function mapperWithProvider(
        bool $generateOnDemand,
        ?CustomObjectMapperProviderInterface $customObjectMapperProvider,
        MappingDefinitionInterface ...$definitions,
    ): ObjectMapper {
        $valueTransformerRegistry = new ValueTransformerRegistry();
        $mappingRegistry          = new MappingRegistry($definitions);
        $customMappingExecutor    = new CustomMappingExecutor($customObjectMapperProvider);

        return new ObjectMapper(
            $mappingRegistry,
            new MapperCache(
                new MappingMetadataFactory($valueTransformerRegistry, mappingRegistry: $mappingRegistry),
                new PhpMapperGenerator(),
                $this->cacheDirectory,
                $valueTransformerRegistry,
                $generateOnDemand,
                $mappingRegistry,
                $customObjectMapperProvider,
                $customMappingExecutor,
            ),
            $customObjectMapperProvider,
            $customMappingExecutor,
        );
    }

    protected function mapperWithTransformers(
        bool $generateOnDemand,
        ValueTransformerRegistry $valueTransformerRegistry,
        MappingDefinitionInterface ...$definitions,
    ): ObjectMapper {
        $mappingRegistry = new MappingRegistry($definitions);

        return new ObjectMapper(
            $mappingRegistry,
            new MapperCache(
                new MappingMetadataFactory($valueTransformerRegistry, mappingRegistry: $mappingRegistry),
                new PhpMapperGenerator(),
                $this->cacheDirectory,
                $valueTransformerRegistry,
                $generateOnDemand,
                $mappingRegistry,
            ),
        );
    }

    /**
     * @param class-string $class
     *
     * @phpstan-assert class-string $alias
     */
    protected function registerClassAlias(string $class, string $alias): void
    {
        class_alias($class, $alias);

        if (! class_exists($alias)) {
            self::fail('Could not register integration test class alias.');
        }
    }
}
