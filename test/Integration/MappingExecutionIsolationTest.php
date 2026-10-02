<?php

declare(strict_types=1);

namespace Sirix\ObjectMapperTest\Integration;

use Fiber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use RuntimeException;
use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Definition\MapRule;
use Sirix\ObjectMapper\Definition\SourceMatchMode;
use Sirix\ObjectMapper\Exception\MappingCompilationFailed;
use Sirix\ObjectMapper\Exception\MappingExecutionFailed;
use Sirix\ObjectMapper\Generator\MapperCache;
use Sirix\ObjectMapper\Generator\PhpMapperGenerator;
use Sirix\ObjectMapper\Metadata\MappingMetadata;
use Sirix\ObjectMapper\Metadata\MappingMetadataFactory;
use Sirix\ObjectMapper\Runtime\MappingRegistry;
use Sirix\ObjectMapper\Runtime\ObjectMapper;
use Sirix\ObjectMapper\Runtime\ValueTransformerRegistry;
use Sirix\ObjectMapperTest\Support\CallbackRelease;
use Sirix\ObjectMapperTest\Support\CallbackReleaseMapper;
use Sirix\ObjectMapperTest\Support\CallbackReleaseProvider;
use Sirix\ObjectMapperTest\Support\CollectionExecutionTrace;
use Sirix\ObjectMapperTest\Support\ObservedReleaseCollectionsDto;
use Sirix\ObjectMapperTest\Support\ObservedReleaseCollectionsSource;
use Sirix\ObjectMapperTest\Support\Release;
use Sirix\ObjectMapperTest\Support\ReleaseCollectionDto;
use Sirix\ObjectMapperTest\Support\ReleaseCollectionSource;
use Sirix\ObjectMapperTest\Support\ReleaseDto;

use WeakReference;

use function function_exists;
use function in_array;
use function xdebug_info;

#[CoversClass(MapperCache::class)]
final class MappingExecutionIsolationTest extends ObjectMapperIntegrationTestCase
{
    #[DataProvider('collectionCacheModes')]
    public function testCollectionCallbackFailureStopsIterationAndReleasesInputs(bool $reusePreparedMappings): void
    {
        [$mapper, $cache]                    = $this->collectionCallbackRuntime(reusePreparedMappings: $reusePreparedMappings);
        $collectionExecutionTrace            = new CollectionExecutionTrace();
        $callbackRelease                     = new CallbackRelease(static function() use ($collectionExecutionTrace): string {
            $collectionExecutionTrace->events[] = 'attempt';

            throw new RuntimeException('sensitive callback details');
        });
        $weakReference                       = WeakReference::create($callbackRelease);
        $observedReleaseCollectionsSource    = new ObservedReleaseCollectionsSource([
            $callbackRelease,
            new CallbackRelease(static function() use ($collectionExecutionTrace): string {
                $collectionExecutionTrace->events[] = 'unexpected';

                return 'late';
            }),
        ], [], $collectionExecutionTrace);
        $weakSource = WeakReference::create($observedReleaseCollectionsSource);

        try {
            $mapper->map($observedReleaseCollectionsSource, ObservedReleaseCollectionsDto::class);
            self::fail('Expected the callback to throw.');
        } catch (MappingExecutionFailed $exception) {
            self::assertStringNotContainsString('sensitive callback details', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
        self::assertSame(['first', 'attempt'], $collectionExecutionTrace->events);
        if (function_exists('xdebug_info') && in_array('develop', xdebug_info('mode'), true)) {
            self::markTestSkipped('Xdebug retains exception arguments; run XDEBUG_MODE=off to verify runtime retention.');
        }
        unset($callbackRelease, $observedReleaseCollectionsSource, $exception);
        self::assertNull($weakReference->get());
        self::assertNull($weakSource->get());
        $observedReleaseCollectionsDto = $mapper->map(new ObservedReleaseCollectionsSource([
            new CallbackRelease(static fn (): string => 'recovered'),
        ], [], new CollectionExecutionTrace()), ObservedReleaseCollectionsDto::class);
        self::assertEquals([new ReleaseDto('recovered')], $observedReleaseCollectionsDto->first);
    }

    #[DataProvider('collectionCacheModes')]
    public function testCollectionFibersAndReentrantRootsKeepIndependentFrames(bool $reusePreparedMappings): void
    {
        [$mapper, $cache] = $this->collectionCallbackRuntime(reusePreparedMappings: $reusePreparedMappings);
        $fiber            = new Fiber(static fn (): ObservedReleaseCollectionsDto => $mapper->map(new ObservedReleaseCollectionsSource([
            new CallbackRelease(static function() use ($mapper): string {
                Fiber::suspend('element');
                $observedReleaseCollectionsDto = $mapper->map(new ObservedReleaseCollectionsSource([
                    new CallbackRelease(static fn (): string => 'inner'),
                ], [], new CollectionExecutionTrace()), ObservedReleaseCollectionsDto::class);

                self::assertEquals([new ReleaseDto('inner')], $observedReleaseCollectionsDto->first);

                return 'outer:inner';
            }),
        ], [], new CollectionExecutionTrace()), ObservedReleaseCollectionsDto::class));
        self::assertSame('element', $fiber->start());

        try {
            $cache->mapCollection([], ObservedReleaseCollectionsSource::class, ObservedReleaseCollectionsDto::class, 'first', CallbackRelease::class, ReleaseDto::class, SourceMatchMode::Exact);
            self::fail('The main execution must not borrow a suspended Fiber frame.');
        } catch (MappingExecutionFailed $exception) {
            self::assertNull($exception->getPrevious());
        }
        $observedReleaseCollectionsDto = $mapper->map(new ObservedReleaseCollectionsSource([
            new CallbackRelease(static fn (): string => 'main'),
        ], [], new CollectionExecutionTrace()), ObservedReleaseCollectionsDto::class);
        self::assertEquals([new ReleaseDto('main')], $observedReleaseCollectionsDto->first);
        $fiber->resume();
        self::assertSame('outer:inner', $fiber->getReturn()->first[0]->version);
    }

    #[DataProvider('collectionCacheModes')]
    public function testReentrantCustomRootsCannotBorrowParentCollectionAuthority(bool $reusePreparedMappings): void
    {
        foreach (['custom', 'provider-get', 'provider-map'] as $kind) {
            $customMapper         = new CallbackReleaseMapper();
            $cacheProvider        = 'custom' === $kind ? null : new CallbackReleaseProvider(new CallbackReleaseMapper());
            [, $cache, $registry] = $this->collectionCallbackRuntime($customMapper, $cacheProvider, $reusePreparedMappings);
            $rootProvider         = 'custom' === $kind ? null : new CallbackReleaseProvider($customMapper);
            $mapper               = new ObjectMapper($registry, $cache, $rootProvider);
            $callback             = static function() use ($cache): void {
                foreach (['collection', 'nested', 'failure'] as $attempt) {
                    try {
                        match ($attempt) {
                            'collection' => $cache->mapCollection([], ObservedReleaseCollectionsSource::class, ObservedReleaseCollectionsDto::class, 'second', Release::class, ReleaseDto::class, SourceMatchMode::Exact),
                            'nested'     => $cache->mapNested(new Release('sibling'), Release::class, ReleaseDto::class, SourceMatchMode::Exact),
                            'failure'    => $cache->collectionElementTypeFailure(ObservedReleaseCollectionsSource::class, ObservedReleaseCollectionsDto::class, 'second', 'forged-key', Release::class, null),
                        };
                        self::fail('An independent custom root borrowed parent authority: ' . $attempt);
                    } catch (MappingExecutionFailed $exception) {
                        self::assertNull($exception->getPrevious());
                    }
                }
            };
            if ('provider-get' === $kind && $rootProvider instanceof CallbackReleaseProvider) {
                $rootProvider->callback = $callback;
            } else {
                $customMapper->callback = $callback;
            }
            $result = $mapper->map(new ObservedReleaseCollectionsSource([], [new Release('restored')], new CollectionExecutionTrace(), static function() use ($mapper): void {
                self::assertEquals(new ReleaseDto('root'), $mapper->map(new CallbackRelease(static fn (): string => 'root'), ReleaseDto::class));
            }), ObservedReleaseCollectionsDto::class);
            self::assertEquals([new ReleaseDto('restored')], $result->second);
            self::assertSame(1, $customMapper->invocations);
            if ($rootProvider instanceof CallbackReleaseProvider && $cacheProvider instanceof CallbackReleaseProvider) {
                self::assertSame(1, $rootProvider->lookups);
                self::assertSame(0, $cacheProvider->lookups);
            }
        }
    }

    #[DataProvider('collectionCacheModes')]
    public function testSuspendedCustomMapperResumesWithIndependentFiberAndMainMappings(bool $reusePreparedMappings): void
    {
        $callbackReleaseMapper           = new CallbackReleaseMapper();
        [$mapper]                        = $this->collectionCallbackRuntime($callbackReleaseMapper, reusePreparedMappings: $reusePreparedMappings);
        $callbackReleaseMapper->callback = static function() use ($mapper): void {
            Fiber::suspend('custom-mapper');
            self::assertEquals(new ReleaseDto('fiber-after-custom'), $mapper->map(new Release('fiber-after-custom'), ReleaseDto::class));
        };
        $fiber = new Fiber(static fn (): ObservedReleaseCollectionsDto => $mapper->map(new ObservedReleaseCollectionsSource([
            new CallbackRelease(static fn (): string => 'custom'),
        ], [], new CollectionExecutionTrace()), ObservedReleaseCollectionsDto::class));

        self::assertSame('custom-mapper', $fiber->start());
        self::assertEquals(new ReleaseDto('main-during-custom'), $mapper->map(new Release('main-during-custom'), ReleaseDto::class));
        $fiber->resume();

        self::assertEquals([new ReleaseDto('custom')], $fiber->getReturn()->first);
        self::assertEquals(new ReleaseDto('main-after-custom'), $mapper->map(new Release('main-after-custom'), ReleaseDto::class));
    }

    #[DataProvider('collectionCacheModes')]
    public function testProviderCollectionRestoresContextAfterSuspendedLookup(bool $reusePreparedMappings): void
    {
        foreach (['resume', 'throw'] as $continuation) {
            $childMapper        = new CallbackReleaseMapper();
            $provider           = new CallbackReleaseProvider($childMapper);
            [$mapper, $cache]   = $this->collectionCallbackRuntime($childMapper, $provider, $reusePreparedMappings);
            $provider->callback = static function() use ($cache): void {
                Fiber::suspend('provider');
                self::assertLeafCannotBorrowParentContext($cache);
            };
            $fiber = new Fiber(static fn (): ObservedReleaseCollectionsDto => $mapper->map(new ObservedReleaseCollectionsSource([
                new CallbackRelease(static fn (): string => 'one'),
                new CallbackRelease(static fn (): string => 'two'),
            ], [new Release('sibling')], new CollectionExecutionTrace()), ObservedReleaseCollectionsDto::class));

            self::assertSame('provider', $fiber->start());
            self::assertSame(1, $provider->lookups);
            self::assertSame(0, $childMapper->invocations);
            self::assertEquals(new ReleaseDto('main'), $mapper->map(new Release('main'), ReleaseDto::class));
            if ('resume' === $continuation) {
                self::assertSame('provider', $fiber->resume());
                $fiber->resume();
                self::assertEquals([new ReleaseDto('one'), new ReleaseDto('two')], $fiber->getReturn()->first);
                self::assertEquals([new ReleaseDto('sibling')], $fiber->getReturn()->second);
                self::assertSame(2, $provider->lookups);
                self::assertSame(2, $childMapper->invocations);
            } else {
                try {
                    $fiber->throw(new RuntimeException('sensitive provider failure'));
                    self::fail('Expected the suspended provider to fail.');
                } catch (MappingExecutionFailed $exception) {
                    self::assertStringNotContainsString('sensitive', $exception->getMessage());
                    self::assertNull($exception->getPrevious());
                }
                self::assertSame(1, $provider->lookups);
                self::assertSame(0, $childMapper->invocations);
            }
            self::assertLeafCannotBorrowParentContext($cache);
            $provider->callback = null;
            $result             = $mapper->map(new ObservedReleaseCollectionsSource([
                new CallbackRelease(static fn (): string => 'recovered'),
            ], [new Release('restored')], new CollectionExecutionTrace()), ObservedReleaseCollectionsDto::class);
            self::assertEquals([new ReleaseDto('recovered')], $result->first);
            self::assertEquals([new ReleaseDto('restored')], $result->second);
        }
    }

    public function testItIsolatesCollectionFailureScopesAcrossInterleavedFibers(): void
    {
        $definitions = [
            new MappingDefinition(Release::class, ReleaseDto::class),
            new MappingDefinition(FiberCollectionFailureSource::class, FiberCollectionFailureDto::class, [
                'releases' => MapRule::fromGetter('getReleases')->collection(Release::class, ReleaseDto::class),
            ]),
        ];
        $mappingRegistry          = new MappingRegistry($definitions);
        $valueTransformerRegistry = new ValueTransformerRegistry();
        $mapperCache              = new MapperCache(
            new MappingMetadataFactory($valueTransformerRegistry, mappingRegistry: $mappingRegistry),
            new PhpMapperGenerator(),
            $this->cacheDirectory,
            $valueTransformerRegistry,
            true,
            $mappingRegistry,
        );
        $objectMapper = new ObjectMapper($mappingRegistry, $mapperCache);

        $reflectionClass = new ReflectionClass($mapperCache);
        self::assertFalse($reflectionClass->hasMethod('beginMapping'));
        self::assertFalse($reflectionClass->hasMethod('endMapping'));

        $suspended = new Fiber(static function() use ($objectMapper, $mapperCache): string {
            try {
                $objectMapper->map(new FiberCollectionFailureSource($mapperCache, true, 1), FiberCollectionFailureDto::class);
            } catch (MappingExecutionFailed $exception) {
                return $exception->getMessage();
            }

            return 'mapping unexpectedly succeeded';
        });
        $interleaved = new Fiber(static function() use ($objectMapper, $mapperCache): string {
            try {
                $objectMapper->map(new FiberCollectionFailureSource($mapperCache, true, 2), FiberCollectionFailureDto::class);
            } catch (MappingExecutionFailed $exception) {
                return $exception->getMessage();
            }

            return 'mapping unexpectedly succeeded';
        });

        $suspended->start();
        $interleaved->start();
        $suspended->resume();
        $interleaved->resume();

        self::assertStringContainsString('integer key 1', $suspended->getReturn());
        self::assertStringContainsString('integer key 2', $interleaved->getReturn());
    }

    public function testItRejectsReentrantMetadataCompilationWithoutResettingTheOuterState(): void
    {
        $childMappingDefinition = new MappingDefinition(Release::class, ReleaseDto::class);
        $rootMappingDefinition  = new MappingDefinition(ReleaseCollectionSource::class, ReleaseCollectionDto::class, [
            'releases' => MapRule::from('releases')->collection(Release::class, ReleaseDto::class),
        ]);
        $reentrantCreateMappingRegistry = new ReentrantCreateMappingRegistry(
            new MappingRegistry([$childMappingDefinition, $rootMappingDefinition]),
            Release::class,
            ReleaseDto::class,
        );
        $mappingMetadataFactory = new MappingMetadataFactory(mappingRegistry: $reentrantCreateMappingRegistry);
        $reentrantCreateMappingRegistry->configureReentry($mappingMetadataFactory, $rootMappingDefinition);

        try {
            $mappingMetadataFactory->create($rootMappingDefinition);
            self::fail('Expected reentrant metadata compilation to fail.');
        } catch (MappingCompilationFailed $exception) {
            self::assertSame('Mapping metadata compilation is already active.', $exception->getMessage());
        }

        self::assertInstanceOf(MappingMetadata::class, $mappingMetadataFactory->create($rootMappingDefinition));
    }

    public function testItRejectsInterleavedFiberMetadataCompilationWithoutResettingTheSuspendedState(): void
    {
        $childMappingDefinition = new MappingDefinition(Release::class, ReleaseDto::class);
        $rootMappingDefinition  = new MappingDefinition(ReleaseCollectionSource::class, ReleaseCollectionDto::class, [
            'releases' => MapRule::from('releases')->collection(Release::class, ReleaseDto::class),
        ]);
        $fiberSuspendingMappingRegistry = new FiberSuspendingMappingRegistry(
            new MappingRegistry([$childMappingDefinition, $rootMappingDefinition]),
            Release::class,
            ReleaseDto::class,
        );
        $mappingMetadataFactory = new MappingMetadataFactory(mappingRegistry: $fiberSuspendingMappingRegistry);
        $fiber                  = new Fiber(static fn (): MappingMetadata => $mappingMetadataFactory->create($rootMappingDefinition));

        $fiber->start();
        self::assertTrue($fiber->isSuspended());

        try {
            $mappingMetadataFactory->create($rootMappingDefinition);
            self::fail('Expected interleaved metadata compilation to fail.');
        } catch (MappingCompilationFailed $exception) {
            self::assertSame('Mapping metadata compilation is already active.', $exception->getMessage());
        }

        $fiber->resume();
        self::assertInstanceOf(MappingMetadata::class, $fiber->getReturn());
    }
}
