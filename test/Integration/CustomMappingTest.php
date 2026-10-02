<?php

declare(strict_types=1);

namespace Sirix\ObjectMapperTest\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sirix\ObjectMapper\Contract\CustomObjectMapperInterface;
use Sirix\ObjectMapper\Contract\CustomObjectMapperProviderInterface;
use Sirix\ObjectMapper\Definition\CustomMappingDefinition;
use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Definition\MapRule;
use Sirix\ObjectMapper\Definition\ProviderCustomMappingDefinition;
use Sirix\ObjectMapper\Definition\SourceMatchMode;
use Sirix\ObjectMapper\Exception\MappingExecutionFailed;
use Sirix\ObjectMapper\Exception\MappingNotRegistered;
use Sirix\ObjectMapper\Generator\MapperCache;
use Sirix\ObjectMapper\Generator\PhpMapperGenerator;
use Sirix\ObjectMapper\Metadata\MappingMetadataFactory;
use Sirix\ObjectMapper\Runtime\CustomMappingExecutor;
use Sirix\ObjectMapper\Runtime\GeneratedMappingExecutionFailed;
use Sirix\ObjectMapper\Runtime\MappingRegistry;
use Sirix\ObjectMapper\Runtime\ObjectMapper;
use Sirix\ObjectMapper\Runtime\ValueTransformerRegistry;
use Sirix\ObjectMapperTest\Support\CallbackRelease;
use Sirix\ObjectMapperTest\Support\CallbackReleaseMapper;
use Sirix\ObjectMapperTest\Support\CallbackReleaseProvider;
use Sirix\ObjectMapperTest\Support\CollectionExecutionTrace;
use Sirix\ObjectMapperTest\Support\ConventionalSource;
use Sirix\ObjectMapperTest\Support\ConventionalTarget;
use Sirix\ObjectMapperTest\Support\CustomChildDto;
use Sirix\ObjectMapperTest\Support\CustomChildHolderDto;
use Sirix\ObjectMapperTest\Support\CustomChildHolderSource;
use Sirix\ObjectMapperTest\Support\CustomChildSource;
use Sirix\ObjectMapperTest\Support\CycleProxyCollectionHolder;
use Sirix\ObjectMapperTest\Support\CycleProxyCollectionHolderDto;
use Sirix\ObjectMapperTest\Support\CycleProxyEntity;
use Sirix\ObjectMapperTest\Support\CycleProxyEntityDto;
use Sirix\ObjectMapperTest\Support\CycleProxyHolder;
use Sirix\ObjectMapperTest\Support\CycleProxyHolderDto;
use Sirix\ObjectMapperTest\Support\DefaultTarget;
use Sirix\ObjectMapperTest\Support\DirectCycleProxy;
use Sirix\ObjectMapperTest\Support\IndirectCycleProxy;
use Sirix\ObjectMapperTest\Support\InvalidCustomMapperProvider;
use Sirix\ObjectMapperTest\Support\NullableCycleProxyHolder;
use Sirix\ObjectMapperTest\Support\NullableCycleProxyHolderDto;
use Sirix\ObjectMapperTest\Support\ObservedReleaseCollectionsDto;
use Sirix\ObjectMapperTest\Support\ObservedReleaseCollectionsSource;
use Sirix\ObjectMapperTest\Support\ProviderMapperLabelService;
use Sirix\ObjectMapperTest\Support\RecordingCustomMapperProvider;
use Sirix\ObjectMapperTest\Support\RecordingCycleProxyTransformer;
use Sirix\ObjectMapperTest\Support\RecordingProviderCustomMapper;
use Sirix\ObjectMapperTest\Support\Release;
use Sirix\ObjectMapperTest\Support\ReleaseCollectionDto;
use Sirix\ObjectMapperTest\Support\ReleaseCollectionSource;
use Sirix\ObjectMapperTest\Support\ReleaseDto;
use Sirix\ObjectMapperTest\Support\ServiceDependentProviderCustomMapper;
use Sirix\ObjectMapperTest\Support\WrongParentCycleProxy;
use stdClass;

use function array_keys;
use function array_map;
use function glob;

#[CoversClass(CustomMappingExecutor::class)]
final class CustomMappingTest extends ObjectMapperIntegrationTestCase
{
    public function testItRejectsProxiesBeforeConventionalTransformersOrCustomMappersRun(): void
    {
        RecordingCycleProxyTransformer::$invocations = 0;
        $conventionalMapper                          = $this->mapperWithTransformers(
            true,
            new ValueTransformerRegistry([new RecordingCycleProxyTransformer()]),
            new MappingDefinition(CycleProxyEntity::class, CycleProxyEntityDto::class, [
                'id' => MapRule::from('id')->through(RecordingCycleProxyTransformer::class),
            ]),
        );
        $recordingCustomMapper = new class implements CustomObjectMapperInterface {
            public int $invocations = 0;

            public function map(object $source): object
            {
                ++$this->invocations;

                return new CycleProxyEntityDto(1);
            }
        };
        $customMapper = $this->mapper(true, new CustomMappingDefinition(
            CycleProxyEntity::class,
            CycleProxyEntityDto::class,
            $recordingCustomMapper,
        ));

        foreach ([$conventionalMapper, $customMapper] as $objectMapper) {
            try {
                $objectMapper->map(new DirectCycleProxy(1), CycleProxyEntityDto::class);
                self::fail('Expected exact source matching to reject the proxy.');
            } catch (MappingNotRegistered $mappingNotRegistered) {
                self::assertInstanceOf(MappingNotRegistered::class, $mappingNotRegistered);
            }
        }

        self::assertSame(0, RecordingCycleProxyTransformer::$invocations);
        self::assertSame(0, $recordingCustomMapper->invocations);
    }

    public function testItUsesTheSameOptInForDirectCustomRootMappings(): void
    {
        $mapper = $this->mapper(true, new CustomMappingDefinition(
            CycleProxyEntity::class,
            CycleProxyEntityDto::class,
            new class implements CustomObjectMapperInterface {
                public function map(object $source): object
                {
                    if (! $source instanceof CycleProxyEntity) {
                        throw new RuntimeException('Expected a Cycle proxy entity.');
                    }

                    return new CycleProxyEntityDto($source->id);
                }
            },
            SourceMatchMode::CycleProxy,
        ));

        self::assertSame(3, $mapper->map(new DirectCycleProxy(3), CycleProxyEntityDto::class)->id);
    }

    public function testItKeepsProviderBackedDefinitionsExactOnlyForProxies(): void
    {
        $objectMapper = $this->mapperWithProvider(
            false,
            new RecordingCustomMapperProvider([]),
            new ProviderCustomMappingDefinition(CycleProxyEntity::class, CycleProxyEntityDto::class, 'provider'),
        );

        $this->expectException(MappingNotRegistered::class);
        $objectMapper->map(new DirectCycleProxy(1), CycleProxyEntityDto::class);
    }

    public function testItAppliesTheChildModeToDirectCustomAndNullableNestedMappings(): void
    {
        $customMappingDefinition = new CustomMappingDefinition(
            CycleProxyEntity::class,
            CycleProxyEntityDto::class,
            new class implements CustomObjectMapperInterface {
                public function map(object $source): object
                {
                    if (! $source instanceof CycleProxyEntity) {
                        throw new RuntimeException('Expected Cycle proxy entity.');
                    }

                    return new CycleProxyEntityDto($source->id);
                }
            },
            SourceMatchMode::CycleProxy,
        );
        $nested = new MappingDefinition(CycleProxyHolder::class, CycleProxyHolderDto::class, [
            'child' => MapRule::from('child')->nested(CycleProxyEntityDto::class),
        ]);
        $nullable = new MappingDefinition(NullableCycleProxyHolder::class, NullableCycleProxyHolderDto::class, [
            'child' => MapRule::from('child')->nested(CycleProxyEntityDto::class),
        ]);
        $collection = new MappingDefinition(CycleProxyCollectionHolder::class, CycleProxyCollectionHolderDto::class, [
            'children' => MapRule::from('children')->collection(CycleProxyEntity::class, CycleProxyEntityDto::class),
        ]);
        $mapper = $this->mapper(true, $customMappingDefinition, $nested, $nullable, $collection);

        self::assertSame(7, $mapper->map(new CycleProxyHolder(new DirectCycleProxy(7)), CycleProxyHolderDto::class)->child->id);
        self::assertNull($mapper->map(new NullableCycleProxyHolder(null), NullableCycleProxyHolderDto::class)->child);
        $nullableCycleProxyHolderDto = $mapper->map(new NullableCycleProxyHolder(new DirectCycleProxy(8)), NullableCycleProxyHolderDto::class);
        self::assertInstanceOf(CycleProxyEntityDto::class, $nullableCycleProxyHolderDto->child);
        self::assertSame(8, $nullableCycleProxyHolderDto->child->id);
        self::assertSame([8, 9], array_map(
            static fn (CycleProxyEntityDto $cycleProxyEntityDto): int => $cycleProxyEntityDto->id,
            $mapper->map(new CycleProxyCollectionHolder([new DirectCycleProxy(8), new CycleProxyEntity(9)]), CycleProxyCollectionHolderDto::class)->children,
        ));
    }

    public function testItRejectsWrongAndIndirectProxiesBeforeDirectCustomCollectionMapping(): void
    {
        $recordingCustomMapper = new class implements CustomObjectMapperInterface {
            public int $invocations = 0;

            public function map(object $source): object
            {
                ++$this->invocations;

                return new CycleProxyEntityDto(1);
            }
        };
        $customMappingDefinition = new CustomMappingDefinition(
            CycleProxyEntity::class,
            CycleProxyEntityDto::class,
            $recordingCustomMapper,
            SourceMatchMode::CycleProxy,
        );
        $mappingDefinition = new MappingDefinition(CycleProxyCollectionHolder::class, CycleProxyCollectionHolderDto::class, [
            'children' => MapRule::from('children')->collection(CycleProxyEntity::class, CycleProxyEntityDto::class),
        ]);
        $mapper       = $this->mapper(true, $customMappingDefinition, $mappingDefinition);
        $createSource = static fn (mixed $children): CycleProxyCollectionHolder => new CycleProxyCollectionHolder($children);

        foreach ([new WrongParentCycleProxy(1), new IndirectCycleProxy(2)] as $value) {
            try {
                $mapper->map($createSource([$value]), CycleProxyCollectionHolderDto::class);
                self::fail('Expected rejected direct-custom collection element.');
            } catch (MappingExecutionFailed $mappingExecutionFailed) {
                self::assertStringContainsString($value::class, $mappingExecutionFailed->getMessage());
            }
        }

        self::assertSame(0, $recordingCustomMapper->invocations);
    }

    public function testItMapsARegisteredPairThroughCustomMapper(): void
    {
        $mapper = $this->mapper(false, new CustomMappingDefinition(
            ConventionalSource::class,
            ConventionalTarget::class,
            new class implements CustomObjectMapperInterface {
                public function map(object $source): object
                {
                    return new ConventionalTarget(42, 'custom', false);
                }
            },
        ));

        $conventionalTarget = $mapper->map(new ConventionalSource(7, 'Ada', true), ConventionalTarget::class);

        self::assertSame(42, $conventionalTarget->id);
        self::assertSame('custom', $conventionalTarget->name);
        self::assertFalse($conventionalTarget->active);
        self::assertSame([], glob($this->cacheDirectory . '/Mapper_*.php') ?: []);
    }

    public function testItWrapsUnexpectedCustomMapperFailuresWithPairContext(): void
    {
        $mapper = $this->mapper(false, new CustomMappingDefinition(
            ConventionalSource::class,
            ConventionalTarget::class,
            new class implements CustomObjectMapperInterface {
                public function map(object $source): object
                {
                    throw new RuntimeException('custom failure');
                }
            },
        ));

        try {
            $mapper->map(new ConventionalSource(7, 'sensitive value', true), ConventionalTarget::class);
            self::fail('Expected custom mapper execution to fail.');
        } catch (MappingExecutionFailed $exception) {
            self::assertStringContainsString(ConventionalSource::class . '->' . ConventionalTarget::class, $exception->getMessage());
            self::assertStringNotContainsString('sensitive value', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function testItSanitizesCustomMappingExecutionFailures(): void
    {
        $mapper = $this->mapper(false, new CustomMappingDefinition(
            ConventionalSource::class,
            ConventionalTarget::class,
            new class implements CustomObjectMapperInterface {
                public function map(object $source): object
                {
                    throw new MappingExecutionFailed('sensitive value');
                }
            },
        ));

        try {
            $mapper->map(new ConventionalSource(7, 'sensitive value', true), ConventionalTarget::class);
            self::fail('Expected custom mapper execution to fail.');
        } catch (MappingExecutionFailed $exception) {
            self::assertStringContainsString(ConventionalSource::class . '->' . ConventionalTarget::class, $exception->getMessage());
            self::assertStringNotContainsString('sensitive value', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function testItRejectsACustomMapperReturningTheWrongTarget(): void
    {
        $mapper = $this->mapper(false, new CustomMappingDefinition(
            ConventionalSource::class,
            ConventionalTarget::class,
            new class implements CustomObjectMapperInterface {
                public function map(object $source): object
                {
                    return new DefaultTarget(1);
                }
            },
        ));

        $this->expectException(MappingExecutionFailed::class);
        $this->expectExceptionMessage(ConventionalSource::class . '->' . ConventionalTarget::class);
        $mapper->map(new ConventionalSource(7, 'Ada', true), ConventionalTarget::class);
    }

    public function testItMapsProviderBackedCustomMappingsAtTheRootOncePerInvocation(): void
    {
        $serviceDependentProviderCustomMapper = new ServiceDependentProviderCustomMapper(new ProviderMapperLabelService());
        $recordingCustomMapperProvider        = new RecordingCustomMapperProvider([
            'child-mapper' => $serviceDependentProviderCustomMapper,
        ]);
        $objectMapper       = $this->mapperWithProvider(
            false,
            $recordingCustomMapperProvider,
            new ProviderCustomMappingDefinition(CustomChildSource::class, CustomChildDto::class, 'child-mapper'),
        );

        self::assertSame('service:first', $objectMapper->map(new CustomChildSource('first'), CustomChildDto::class)->label);
        self::assertSame('service:second', $objectMapper->map(new CustomChildSource('second'), CustomChildDto::class)->label);
        self::assertSame(['child-mapper', 'child-mapper'], $recordingCustomMapperProvider->mapperIds);
        self::assertSame(2, $recordingCustomMapperProvider->lookups);
        self::assertSame(2, $serviceDependentProviderCustomMapper->invocations);
        self::assertSame([], glob($this->cacheDirectory . '/Mapper_*.php') ?: []);
    }

    public function testItSanitizesProviderBackedCustomMappingFailures(): void
    {
        $mapperId        = 'sensitive-mapper-id';
        $sensitiveDetail = 'sensitive provider failure';
        $definitions     = [new ProviderCustomMappingDefinition(CustomChildSource::class, CustomChildDto::class, $mapperId)];
        $providers       = [
            new class($sensitiveDetail) implements CustomObjectMapperProviderInterface {
                public function __construct(private readonly string $sensitiveDetail) {}

                public function get(string $mapperId): CustomObjectMapperInterface
                {
                    throw new RuntimeException($this->sensitiveDetail);
                }
            },
            new RecordingCustomMapperProvider([
                $mapperId => new class($sensitiveDetail) implements CustomObjectMapperInterface {
                    public function __construct(private readonly string $sensitiveDetail) {}

                    public function map(object $source): object
                    {
                        throw new RuntimeException($this->sensitiveDetail);
                    }
                },
            ]),
            new RecordingCustomMapperProvider([
                $mapperId => new class implements CustomObjectMapperInterface {
                    public function map(object $source): object
                    {
                        return new DefaultTarget(1);
                    }
                },
            ]),
            new InvalidCustomMapperProvider(),
        ];

        foreach ($providers as $provider) {
            try {
                $this->mapperWithProvider(false, $provider, ...$definitions)
                    ->map(new CustomChildSource('sensitive source value'), CustomChildDto::class)
                ;
                self::fail('Expected provider-backed custom mapper execution to fail.');
            } catch (MappingExecutionFailed $exception) {
                self::assertSame('Could not execute mapping ' . CustomChildSource::class . '->' . CustomChildDto::class . '.', $exception->getMessage());
                self::assertStringNotContainsString($mapperId, $exception->getMessage());
                self::assertStringNotContainsString($sensitiveDetail, $exception->getMessage());
                self::assertStringNotContainsString('sensitive source value', $exception->getMessage());
                self::assertNull($exception->getPrevious());
            }
        }

        try {
            $this->mapper(false, ...$definitions)->map(new CustomChildSource('sensitive source value'), CustomChildDto::class);
            self::fail('Expected a missing provider to fail.');
        } catch (MappingExecutionFailed $exception) {
            self::assertSame('Could not execute mapping ' . CustomChildSource::class . '->' . CustomChildDto::class . '.', $exception->getMessage());
            self::assertStringNotContainsString($mapperId, $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function testProviderBackedNestedAndCollectionMappingsResolveOnlyDeclaredChildren(): void
    {
        $recordingProviderCustomMapper     = new RecordingProviderCustomMapper();
        $recordingCustomMapperProvider     = new RecordingCustomMapperProvider([
            'child-mapper' => $recordingProviderCustomMapper,
        ]);
        $objectMapper       = $this->mapperWithProvider(
            true,
            $recordingCustomMapperProvider,
            new ProviderCustomMappingDefinition(CustomChildSource::class, CustomChildDto::class, 'child-mapper'),
            new ProviderCustomMappingDefinition(Release::class, ReleaseDto::class, 'child-mapper'),
            new MappingDefinition(CustomChildHolderSource::class, CustomChildHolderDto::class, [
                'child' => MapRule::from('child')->nested(CustomChildDto::class),
            ]),
            new MappingDefinition(ReleaseCollectionSource::class, ReleaseCollectionDto::class, [
                'releases' => MapRule::from('releases')->collection(Release::class, ReleaseDto::class),
            ]),
        );

        self::assertSame('nested', $objectMapper->map(new CustomChildHolderSource(new CustomChildSource('nested')), CustomChildHolderDto::class)->child->label);
        $releaseCollectionDto = $objectMapper->map(new ReleaseCollectionSource([
            'first' => new Release('1.0'),
            9       => new Release('2.0'),
        ]), ReleaseCollectionDto::class);

        self::assertSame(['1.0', '2.0'], array_map(static fn (ReleaseDto $releaseDto): string => $releaseDto->version, $releaseCollectionDto->releases));
        self::assertSame([0, 1], array_keys($releaseCollectionDto->releases));
        self::assertSame(['child-mapper', 'child-mapper', 'child-mapper'], $recordingCustomMapperProvider->mapperIds);
        self::assertSame(3, $recordingCustomMapperProvider->lookups);
        self::assertSame(3, $recordingProviderCustomMapper->invocations);
    }

    public function testItSanitizesProviderBackedNestedAndCollectionMappingFailures(): void
    {
        $mapperId        = 'sensitive-provider-mapper-id';
        $sensitiveDetail = 'sensitive provider mapper output';
        $objectMapper    = $this->mapperWithProvider(
            true,
            new RecordingCustomMapperProvider([
                $mapperId => new class($sensitiveDetail) implements CustomObjectMapperInterface {
                    public function __construct(private readonly string $sensitiveDetail) {}

                    public function map(object $source): object
                    {
                        return new ConventionalTarget(1, $this->sensitiveDetail, true);
                    }
                },
            ]),
            new ProviderCustomMappingDefinition(CustomChildSource::class, CustomChildDto::class, $mapperId),
            new ProviderCustomMappingDefinition(Release::class, ReleaseDto::class, $mapperId),
            new MappingDefinition(CustomChildHolderSource::class, CustomChildHolderDto::class, [
                'child' => MapRule::from('child')->nested(CustomChildDto::class),
            ]),
            new MappingDefinition(ReleaseCollectionSource::class, ReleaseCollectionDto::class, [
                'releases' => MapRule::from('releases')->collection(Release::class, ReleaseDto::class),
            ]),
        );

        foreach ([
            [new CustomChildHolderSource(new CustomChildSource('sensitive nested source')), CustomChildHolderDto::class, CustomChildHolderSource::class . '->' . CustomChildHolderDto::class],
            [new ReleaseCollectionSource([new Release('sensitive collection source')]), ReleaseCollectionDto::class, ReleaseCollectionSource::class . '->' . ReleaseCollectionDto::class],
        ] as [$source, $target, $pair]) {
            try {
                $objectMapper->map($source, $target);
                self::fail('Expected provider-backed structural mapping to fail.');
            } catch (MappingExecutionFailed $exception) {
                self::assertSame('Could not execute mapping ' . $pair . '.', $exception->getMessage());
                self::assertStringNotContainsString($mapperId, $exception->getMessage());
                self::assertStringNotContainsString($sensitiveDetail, $exception->getMessage());
                self::assertStringNotContainsString('sensitive nested source', $exception->getMessage());
                self::assertStringNotContainsString('sensitive collection source', $exception->getMessage());
                self::assertNull($exception->getPrevious());
            }
        }
    }

    public function testProviderCollectionsResolveOnlyElementsBeforeAnInvalidElement(): void
    {
        foreach ([0, 1, 2] as $invalidPosition) {
            $childMapper = new RecordingProviderCustomMapper();
            $provider    = new RecordingCustomMapperProvider([
                'child' => $childMapper,
            ]);
            $mapper = $this->mapperWithProvider(
                true,
                $provider,
                new ProviderCustomMappingDefinition(Release::class, ReleaseDto::class, 'child'),
                new MappingDefinition(ObservedReleaseCollectionsSource::class, ObservedReleaseCollectionsDto::class, [
                    'first'  => MapRule::fromGetter('getFirst')->collection(Release::class, ReleaseDto::class),
                    'second' => MapRule::fromGetter('getSecond')->collection(Release::class, ReleaseDto::class),
                ]),
            );
            $items                   = [new Release('a'), new Release('b'), new Release('c')];
            $items[$invalidPosition] = new stdClass();

            try {
                $mapper->map(new ObservedReleaseCollectionsSource($items, [], new CollectionExecutionTrace()), ObservedReleaseCollectionsDto::class);
                self::fail('Expected the invalid provider collection element to fail.');
            } catch (MappingExecutionFailed) {
                self::assertSame($invalidPosition, $provider->lookups);
                self::assertSame($invalidPosition, $childMapper->invocations);
            }
        }
    }

    #[DataProvider('collectionCacheModes')]
    public function testCustomCollectionCallbacksCannotBorrowParentAuthority(bool $reusePreparedMappings): void
    {
        foreach (['custom', 'provider-get', 'provider-map'] as $callbackKind) {
            $customMapper     = new CallbackReleaseMapper();
            $provider         = 'custom' === $callbackKind ? null : new CallbackReleaseProvider($customMapper);
            [$mapper, $cache] = $this->collectionCallbackRuntime($customMapper, $provider, $reusePreparedMappings);
            $events           = new CollectionExecutionTrace();
            $callback         = static function() use ($cache, $events): void {
                $events->events[] = 'callback';
                foreach (['collection', 'nested', 'failure'] as $attempt) {
                    try {
                        match ($attempt) {
                            'collection' => $cache->mapCollection([new Release('sibling')], ObservedReleaseCollectionsSource::class, ObservedReleaseCollectionsDto::class, 'second', Release::class, ReleaseDto::class, SourceMatchMode::Exact),
                            'nested'     => $cache->mapNested(new Release('sibling'), Release::class, ReleaseDto::class, SourceMatchMode::Exact),
                            'failure'    => $cache->collectionElementTypeFailure(ObservedReleaseCollectionsSource::class, ObservedReleaseCollectionsDto::class, 'second', 'forged-sensitive-key', Release::class, null),
                        };
                        self::fail('A custom callback borrowed parent authority: ' . $attempt);
                    } catch (MappingExecutionFailed $exception) {
                        self::assertNull($exception->getPrevious());
                        $events->events[] = $attempt;
                    }
                }
            };
            if ('provider-get' === $callbackKind && $provider instanceof CallbackReleaseProvider) {
                $provider->callback = $callback;
            } else {
                $customMapper->callback = $callback;
            }
            $source = new ObservedReleaseCollectionsSource([
                new CallbackRelease(static fn (): string => 'one'),
                new CallbackRelease(static fn (): string => 'two'),
            ], [new Release('sibling')], new CollectionExecutionTrace());
            $result = $mapper->map($source, ObservedReleaseCollectionsDto::class);
            self::assertEquals([new ReleaseDto('one'), new ReleaseDto('two')], $result->first);
            self::assertEquals([new ReleaseDto('sibling')], $result->second);
            self::assertSame(['callback', 'collection', 'nested', 'failure', 'callback', 'collection', 'nested', 'failure'], $events->events);
            self::assertSame(2, $customMapper->invocations);
            self::assertSame($provider instanceof CallbackReleaseProvider ? 2 : null, $provider?->lookups);

            $throwingCallback = static function(): never {
                throw new RuntimeException('sensitive fallback failure');
            };
            if ('provider-get' === $callbackKind) {
                $provider->callback = $throwingCallback;
            } else {
                $customMapper->callback = $throwingCallback;
            }

            try {
                $mapper->map($source, ObservedReleaseCollectionsDto::class);
                self::fail('Expected fallback callback failure.');
            } catch (MappingExecutionFailed $exception) {
                self::assertStringNotContainsString('sensitive fallback failure', $exception->getMessage());
                self::assertNull($exception->getPrevious());
            }
            self::assertSame('provider-get' === $callbackKind ? 2 : 3, $customMapper->invocations);
            self::assertSame($provider instanceof CallbackReleaseProvider ? 3 : null, $provider?->lookups);
            $customMapper->callback = null;
            if ($provider instanceof CallbackReleaseProvider) {
                $provider->callback = null;
            }
            $recovered = $mapper->map($source, ObservedReleaseCollectionsDto::class);
            self::assertEquals([new ReleaseDto('one'), new ReleaseDto('two')], $recovered->first);
            self::assertEquals([new ReleaseDto('sibling')], $recovered->second);
        }
    }

    #[DataProvider('collectionCacheModes')]
    public function testCustomCollectionStopsAtWrongTargetAndRecovers(bool $reusePreparedMappings): void
    {
        foreach ([false, true] as $providerBacked) {
            $childMapper = new class implements CustomObjectMapperInterface {
                public int $calls = 0;

                public function map(object $source): object
                {
                    ++$this->calls;
                    if (2 === $this->calls) {
                        return new stdClass();
                    }
                    TestCase::assertInstanceOf(Release::class, $source);

                    return new ReleaseDto($source->version);
                }
            };
            $provider = new RecordingCustomMapperProvider([
                'child' => $childMapper,
            ]);
            $registry = new MappingRegistry([
                $providerBacked
                    ? new ProviderCustomMappingDefinition(Release::class, ReleaseDto::class, 'child')
                    : new CustomMappingDefinition(Release::class, ReleaseDto::class, $childMapper),
                new MappingDefinition(ReleaseCollectionSource::class, ReleaseCollectionDto::class, [
                    'releases' => MapRule::from('releases')->collection(Release::class, ReleaseDto::class),
                ]),
            ]);
            $cache = new MapperCache(
                new MappingMetadataFactory(mappingRegistry: $registry),
                new PhpMapperGenerator(), $this->cacheDirectory, new ValueTransformerRegistry(),
                generateOnDemand: true, mappingRegistry: $registry, customObjectMapperProvider: $provider,
                reusePreparedMappings: $reusePreparedMappings,
            );
            $mapper = new ObjectMapper($registry, $cache);
            $mapper->warmup();
            self::assertEquals(new ReleaseCollectionDto([]), $mapper->map(new ReleaseCollectionSource([]), ReleaseCollectionDto::class));
            self::assertSame(0, $provider->lookups);
            self::assertSame(0, $childMapper->calls);

            try {
                $mapper->map(new ReleaseCollectionSource([
                    'first' => new Release('one'),
                    9       => new Release('two'),
                    'last'  => new Release('three'),
                ]), ReleaseCollectionDto::class);
                self::fail('Expected the second mapped target to fail validation.');
            } catch (MappingExecutionFailed $exception) {
                self::assertSame('Could not execute mapping ' . ReleaseCollectionSource::class . '->' . ReleaseCollectionDto::class . '.', $exception->getMessage());
                self::assertNull($exception->getPrevious());
            }
            self::assertSame(2, $childMapper->calls);
            self::assertSame($providerBacked ? 2 : 0, $provider->lookups);
            self::assertEquals(new ReleaseCollectionDto([new ReleaseDto('recovered')]), $mapper->map(new ReleaseCollectionSource([
                'original-key' => new Release('recovered'),
            ]), ReleaseCollectionDto::class));
            self::assertSame(3, $childMapper->calls);
            self::assertSame($providerBacked ? 3 : 0, $provider->lookups);
        }
    }

    public function testItSanitizesNestedCustomMapperFailures(): void
    {
        $mapper = $this->mapper(
            true,
            new CustomMappingDefinition(
                CustomChildSource::class,
                CustomChildDto::class,
                new class implements CustomObjectMapperInterface {
                    public function map(object $source): object
                    {
                        throw new GeneratedMappingExecutionFailed(new stdClass());
                    }
                },
            ),
            new MappingDefinition(CustomChildHolderSource::class, CustomChildHolderDto::class, [
                'child' => MapRule::from('child')->nested(CustomChildDto::class),
            ]),
        );

        try {
            $mapper->map(new CustomChildHolderSource(new CustomChildSource('sensitive source value')), CustomChildHolderDto::class);
            self::fail('Expected nested custom mapping to fail.');
        } catch (MappingExecutionFailed $exception) {
            self::assertStringContainsString(CustomChildHolderSource::class . '->' . CustomChildHolderDto::class, $exception->getMessage());
            self::assertStringNotContainsString('forged-sensitive-key', $exception->getMessage());
        }
    }

    public function testItAcceptsCustomMapperTargetSubtypesAtRootNestedAndCollectionBoundaries(): void
    {
        $mapper = $this->mapper(
            true,
            new CustomMappingDefinition(
                ExactChild::class,
                PolymorphicTarget::class,
                new class implements CustomObjectMapperInterface {
                    public function map(object $source): object
                    {
                        return new PolymorphicTargetSubtype();
                    }
                },
            ),
            new MappingDefinition(PolymorphicHolderSource::class, PolymorphicHolderDto::class, [
                'child'    => MapRule::from('child')->nested(PolymorphicTarget::class),
                'children' => MapRule::from('children')->collection(ExactChild::class, PolymorphicTarget::class),
            ]),
        );

        self::assertInstanceOf(PolymorphicTargetSubtype::class, $mapper->map(new ExactChild('root'), PolymorphicTarget::class));

        $polymorphicHolderDto = $mapper->map(
            new PolymorphicHolderSource(new ExactChild('nested'), [new ExactChild('collection')]),
            PolymorphicHolderDto::class,
        );
        self::assertInstanceOf(PolymorphicTargetSubtype::class, $polymorphicHolderDto->child);
        self::assertInstanceOf(PolymorphicTargetSubtype::class, $polymorphicHolderDto->children[0]);
    }
}
