<?php

declare(strict_types=1);

namespace Sirix\ObjectMapperTest\Integration;

use DateTimeImmutable;
use Fiber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Sirix\ObjectMapper\Definition\CustomMappingDefinition;
use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Definition\MapRule;
use Sirix\ObjectMapper\Definition\ProviderCustomMappingDefinition;
use Sirix\ObjectMapper\Definition\SourceMatchMode;
use Sirix\ObjectMapper\Exception\MappingCompilationFailed;
use Sirix\ObjectMapper\Exception\MappingExecutionFailed;
use Sirix\ObjectMapper\Exception\MappingFailureReason;
use Sirix\ObjectMapper\Generator\MapperCache;
use Sirix\ObjectMapper\Generator\PhpMapperGenerator;
use Sirix\ObjectMapper\Metadata\MappingMetadataFactory;
use Sirix\ObjectMapper\Runtime\GeneratedMappingExecutionFailed;
use Sirix\ObjectMapper\Runtime\MappingExecution;
use Sirix\ObjectMapper\Runtime\MappingRegistry;
use Sirix\ObjectMapper\Runtime\ObjectMapper;
use Sirix\ObjectMapper\Runtime\ValueTransformerRegistry;
use Sirix\ObjectMapperTest\Support\AccessToken;
use Sirix\ObjectMapperTest\Support\CallbackLeafDto;
use Sirix\ObjectMapperTest\Support\CallbackLeafHolderDto;
use Sirix\ObjectMapperTest\Support\CallbackLeafHolderSource;
use Sirix\ObjectMapperTest\Support\CallbackLeafSource;
use Sirix\ObjectMapperTest\Support\CallbackRelease;
use Sirix\ObjectMapperTest\Support\CollectionExecutionTrace;
use Sirix\ObjectMapperTest\Support\ConstantSource;
use Sirix\ObjectMapperTest\Support\ConventionalSource;
use Sirix\ObjectMapperTest\Support\ConventionalTarget;
use Sirix\ObjectMapperTest\Support\CustomChildDto;
use Sirix\ObjectMapperTest\Support\CustomChildSource;
use Sirix\ObjectMapperTest\Support\CycleProxyEntity;
use Sirix\ObjectMapperTest\Support\CycleProxyEntityDto;
use Sirix\ObjectMapperTest\Support\CycleProxyHolder;
use Sirix\ObjectMapperTest\Support\CycleProxyHolderDto;
use Sirix\ObjectMapperTest\Support\DateTimeToAtomTransformer;
use Sirix\ObjectMapperTest\Support\DefaultSource;
use Sirix\ObjectMapperTest\Support\DefaultTarget;
use Sirix\ObjectMapperTest\Support\DirectCycleProxy;
use Sirix\ObjectMapperTest\Support\ExplicitMethodSource;
use Sirix\ObjectMapperTest\Support\ExplicitMethodTarget;
use Sirix\ObjectMapperTest\Support\IndirectCycleProxy;
use Sirix\ObjectMapperTest\Support\MissingTarget;
use Sirix\ObjectMapperTest\Support\MixedConstantTarget;
use Sirix\ObjectMapperTest\Support\NormalCycleEntitySubclass;
use Sirix\ObjectMapperTest\Support\ObservedReleaseCollectionsDto;
use Sirix\ObjectMapperTest\Support\ObservedReleaseCollectionsSource;
use Sirix\ObjectMapperTest\Support\ProfileSource;
use Sirix\ObjectMapperTest\Support\ProfileTarget;
use Sirix\ObjectMapperTest\Support\RecordingCustomChildMapper;
use Sirix\ObjectMapperTest\Support\RecordingCustomMapperProvider;
use Sirix\ObjectMapperTest\Support\RecordingCycleProxyTransformer;
use Sirix\ObjectMapperTest\Support\RecordingProviderCustomMapper;
use Sirix\ObjectMapperTest\Support\Release;
use Sirix\ObjectMapperTest\Support\ReleaseCollectionDto;
use Sirix\ObjectMapperTest\Support\ReleaseCollectionSource;
use Sirix\ObjectMapperTest\Support\ReleaseDto;
use Sirix\ObjectMapperTest\Support\ThreeLevelLeafDto;
use Sirix\ObjectMapperTest\Support\ThreeLevelLeafSource;
use Sirix\ObjectMapperTest\Support\ThreeLevelMiddleDto;
use Sirix\ObjectMapperTest\Support\ThreeLevelMiddleSource;
use Sirix\ObjectMapperTest\Support\ThreeLevelRootDto;
use Sirix\ObjectMapperTest\Support\ThreeLevelRootSource;
use Sirix\ObjectMapperTest\Support\Uuid;
use Sirix\ObjectMapperTest\Support\UuidToStringTransformer;
use stdClass;

use WeakMap;
use WeakReference;

use function bin2hex;
use function chmod;
use function count;
use function file_get_contents;
use function file_put_contents;
use function fileperms;
use function function_exists;
use function gc_collect_cycles;
use function glob;
use function hash;
use function in_array;
use function is_dir;
use function json_encode;
use function mkdir;
use function random_bytes;
use function strlen;
use function xdebug_info;

#[CoversClass(MapperCache::class)]
final class PreparedMappingTest extends ObjectMapperIntegrationTestCase
{
    public function testFormatSevenCacheDoesNotSatisfyFormatEightProductionLookup(): void
    {
        $mappingDefinition        = new MappingDefinition(ConventionalSource::class, ConventionalTarget::class);
        $phpMapperGenerator       = new PhpMapperGenerator();
        $mappingMetadata          = (new MappingMetadataFactory())->create($mappingDefinition);
        $reflectionMethod         = new ReflectionMethod(PhpMapperGenerator::class, 'normalizedMetadata');
        $legacyMetadata           = $reflectionMethod->invoke($phpMapperGenerator, $mappingMetadata);
        $legacyMetadata['format'] = '7';
        $legacyKey                = hash('sha256', json_encode($legacyMetadata, JSON_THROW_ON_ERROR));

        if (! is_dir($this->cacheDirectory)) {
            mkdir($this->cacheDirectory, 0o700, true);
        }

        $legacyPath = $this->cacheDirectory . '/Mapper_' . $legacyKey . '.php';
        file_put_contents($legacyPath, '<?php // legacy format seven cache');
        chmod($legacyPath, 0o600);

        $formatEightKey = $phpMapperGenerator->cacheKey($mappingMetadata);
        file_put_contents($this->cacheDirectory . '/.Mapper_' . $formatEightKey . '.lock', '');

        $objectMapper = $this->mapper(false, $mappingDefinition);

        try {
            $objectMapper->map(new ConventionalSource(7, 'Ada', true), ConventionalTarget::class);
            self::fail('Expected a format-8 production cache miss.');
        } catch (MappingCompilationFailed $exception) {
            self::assertStringContainsString('Warm the cache before production use.', $exception->getMessage());
        }

        self::assertSame('<?php // legacy format seven cache', file_get_contents($legacyPath));

        $warmMapper = $this->mapper(true, $mappingDefinition);

        self::assertSame(7, $warmMapper->map(new ConventionalSource(7, 'Ada', true), ConventionalTarget::class)->id);
        self::assertCount(2, glob($this->cacheDirectory . '/Mapper_*.php') ?: []);
    }

    public function testItMapsProxyEnabledDefinitionsFromFreshAndWarmedCaches(): void
    {
        $mappingDefinition  = new MappingDefinition(CycleProxyEntity::class, CycleProxyEntityDto::class, sourceMatch: SourceMatchMode::CycleProxy);
        $objectMapper       = $this->mapper(true, $mappingDefinition);
        self::assertSame(1, $objectMapper->map(new CycleProxyEntity(1), CycleProxyEntityDto::class)->id);
        self::assertSame(2, $objectMapper->map(new DirectCycleProxy(2), CycleProxyEntityDto::class)->id);

        $this->mapper(false, $mappingDefinition)->warmup();
        $warmedMapper = $this->mapper(false, $mappingDefinition);
        self::assertSame(3, $warmedMapper->map(new CycleProxyEntity(3), CycleProxyEntityDto::class)->id);
        self::assertSame(4, $warmedMapper->map(new DirectCycleProxy(4), CycleProxyEntityDto::class)->id);
    }

    public function testItUsesDifferentCacheFilesForExactAndProxyEnabledDefinitions(): void
    {
        $this->mapper(true, new MappingDefinition(CycleProxyEntity::class, CycleProxyEntityDto::class))
            ->map(new CycleProxyEntity(1), CycleProxyEntityDto::class)
        ;
        $this->mapper(true, new MappingDefinition(
            CycleProxyEntity::class,
            CycleProxyEntityDto::class,
            sourceMatch: SourceMatchMode::CycleProxy,
        ))->map(new CycleProxyEntity(1), CycleProxyEntityDto::class);

        self::assertCount(2, glob($this->cacheDirectory . '/Mapper_*.php') ?: []);
    }

    public function testItRejectsProductionCacheMisses(): void
    {
        $mapper = $this->mapper(false, new MappingDefinition(DefaultSource::class, DefaultTarget::class));

        $this->expectException(MappingCompilationFailed::class);
        $mapper->map(new DefaultSource(1), DefaultTarget::class);
    }

    public function testItMapsConstantsFromFreshAndWarmedCachesWithoutReadingTheSource(): void
    {
        $mappingDefinition = new MappingDefinition(
            ConstantSource::class,
            MixedConstantTarget::class,
            [
                'value' => MapRule::constant("configured\n☃"),
            ],
        );

        $mixedConstantTarget = $this->mapper(true, $mappingDefinition)->map(new ConstantSource(), MixedConstantTarget::class);
        self::assertSame("configured\n☃", $mixedConstantTarget->value);

        $this->mapper(false, $mappingDefinition)->warmup();
        $warmed = $this->mapper(false, $mappingDefinition)->map(new ConstantSource(), MixedConstantTarget::class);
        self::assertSame("configured\n☃", $warmed->value);

        $cacheFiles = glob($this->cacheDirectory . '/Mapper_*.php') ?: [];
        self::assertCount(1, $cacheFiles);
        self::assertSame(0o600, fileperms($cacheFiles[0]) & 0o777);
        self::assertStringContainsString("value: 'configured' . \"\\x0A\" . '☃',", (string) file_get_contents($cacheFiles[0]));
    }

    public function testItRetainsCanonicalDefinitionCacheBehavior(): void
    {
        $mappingDefinition = new MappingDefinition(DefaultSource::class, DefaultTarget::class);
        $mapper            = $this->mapper(false, $mappingDefinition);

        self::assertSame('8', (new ReflectionClass(PhpMapperGenerator::class))->getConstant('FORMAT_VERSION'));
        self::assertSame(DefaultSource::class, $mappingDefinition->source());
        self::assertSame([$mappingDefinition->key()], $mapper->warmup());
        self::assertSame('default', $mapper->map(new DefaultSource(1), DefaultTarget::class)->label);
    }

    public function testItLoadsAProductionMapperWarmedByAnotherCacheInstance(): void
    {
        $mappingDefinition = new MappingDefinition(DefaultSource::class, DefaultTarget::class);
        $this->mapper(false, $mappingDefinition)->warmup();

        $defaultTarget = $this->mapper(false, $mappingDefinition)->map(new DefaultSource(1), DefaultTarget::class);

        self::assertSame('default', $defaultTarget->label);
    }

    public function testItLoadsAProductionProfileMapperWarmedByAnotherCacheInstance(): void
    {
        $mappingDefinition = new MappingDefinition(
            ProfileSource::class,
            ProfileTarget::class,
            [
                'id'    => MapRule::from('uuid'),
                'email' => MapRule::fromGetter('getPrimaryEmail'),
            ],
            ['passwordHash'],
        );
        $this->mapper(false, $mappingDefinition)->warmup();

        $profileTarget = $this->mapper(false, $mappingDefinition)->map(
            new ProfileSource(17, 'not-in-errors'),
            ProfileTarget::class,
        );

        self::assertSame(17, $profileTarget->id);
        self::assertSame('ada@example.test', $profileTarget->email);
    }

    public function testItRejectsAnUnsafeCacheDirectory(): void
    {
        mkdir($this->cacheDirectory, 0o700);
        chmod($this->cacheDirectory, 0o777);

        $mapper = $this->mapper(true, new MappingDefinition(DefaultSource::class, DefaultTarget::class));

        $this->expectException(MappingCompilationFailed::class);
        $mapper->map(new DefaultSource(1), DefaultTarget::class);
    }

    public function testItMapsExplicitMethodsThroughRegisteredTransformersAndReloadsWarmCache(): void
    {
        $mappingDefinition = new MappingDefinition(
            ExplicitMethodSource::class,
            ExplicitMethodTarget::class,
            [
                'id'        => MapRule::fromMethod('getIdentifier')->through(UuidToStringTransformer::class),
                'createdAt' => MapRule::fromMethod('createdAt')->through(DateTimeToAtomTransformer::class),
                'slug'      => MapRule::fromMethod('slug'),
            ],
        );
        $valueTransformerRegistry = new ValueTransformerRegistry([
            new UuidToStringTransformer(),
            new DateTimeToAtomTransformer(),
        ]);

        $this->mapperWithTransformers(false, $valueTransformerRegistry, $mappingDefinition)->warmup();
        $explicitMethodTarget = $this->mapperWithTransformers(false, $valueTransformerRegistry, $mappingDefinition)->map(
            new ExplicitMethodSource(new Uuid('550e8400-e29b-41d4-a716-446655440000'), new DateTimeImmutable('2026-08-28T10:00:00+00:00')),
            ExplicitMethodTarget::class,
        );

        self::assertSame('550e8400-e29b-41d4-a716-446655440000', $explicitMethodTarget->id);
        self::assertSame('2026-08-28T10:00:00+00:00', $explicitMethodTarget->createdAt);
        self::assertSame('explicit-slug', $explicitMethodTarget->slug);
    }

    #[DataProvider('leafReentryModes')]
    public function testLeafReentryRestoresIsolatedScopesAcrossFibers(bool $prepared, string $callbackKind, bool $nested): void
    {
        [$mapper, $cache, $target]                    = $this->leafCallbackRuntime($prepared, $callbackKind);
        $collectionExecutionTrace                     = new CollectionExecutionTrace();
        $callbackLeafSource                           = new CallbackLeafSource(static function() use ($mapper, $cache, $collectionExecutionTrace): string {
            $collectionExecutionTrace->events[] = 'callback';
            self::assertLeafCannotBorrowParentContext($cache);
            if (Fiber::getCurrent() instanceof Fiber) {
                Fiber::suspend('leaf');
            }

            try {
                $mapper->map(new FiberCollectionFailureSource($cache, false, 17), FiberCollectionFailureDto::class);
                self::fail('Expected the independent collection root to fail.');
            } catch (MappingExecutionFailed $exception) {
                self::assertStringContainsString(FiberCollectionFailureSource::class . '->' . FiberCollectionFailureDto::class, $exception->getMessage());
                self::assertStringContainsString('integer key 17', $exception->getMessage());
                self::assertStringNotContainsString('fiber-secret', $exception->getMessage());
                self::assertNull($exception->getPrevious());
            }
            self::assertLeafCannotBorrowParentContext($cache);
            self::assertEquals(new ReleaseDto('inner'), $mapper->map(new Release('inner'), ReleaseDto::class));

            return 'leaf';
        });
        $callbackLeafHolderSource = $this->leafCallbackParent($mapper, $callbackLeafSource, $target, $nested);
        $mapper->warmup();
        self::assertSame([], $collectionExecutionTrace->events);
        $fiber = new Fiber(static fn (): CallbackLeafHolderDto => $mapper->map($callbackLeafHolderSource, CallbackLeafHolderDto::class));
        self::assertSame('leaf', $fiber->start());
        self::assertLeafCannotBorrowParentContext($cache);
        self::assertEquals(new ReleaseDto('main'), $cache->map(new MappingDefinition(Release::class, ReleaseDto::class), new Release('main')));
        $fiber->resume();
        self::assertTrue($fiber->isTerminated());
        self::assertEquals([new ReleaseDto('sibling')], $fiber->getReturn()->releases);
        if ($nested) {
            self::assertInstanceOf($target, $fiber->getReturn()->leaf);
            self::assertSame('leaf', $fiber->getReturn()->leaf->version);
        }
        self::assertLeafCannotBorrowParentContext($cache);
        self::assertEquals([new ReleaseDto('sibling')], $mapper->map($callbackLeafHolderSource, CallbackLeafHolderDto::class)->releases);
        self::assertSame(['callback', 'callback'], $collectionExecutionTrace->events);
    }

    #[DataProvider('leafReentryModes')]
    public function testLeafFailureCannotAcquireParentDiagnosticsAndCleansUp(bool $prepared, string $callbackKind, bool $nested): void
    {
        [$mapper, $cache, $target]                    = $this->leafCallbackRuntime($prepared, $callbackKind);
        $collectionExecutionTrace                     = new CollectionExecutionTrace();
        $callbackLeafSource                           = new CallbackLeafSource(static function() use ($cache, $collectionExecutionTrace): string {
            $collectionExecutionTrace->events[] = 'callback';
            self::assertLeafCannotBorrowParentContext($cache);
            if (1 === count($collectionExecutionTrace->events)) {
                $cache->collectionElementTypeFailure(CallbackLeafHolderSource::class, CallbackLeafHolderDto::class, 'releases', 'sensitive-parent-key', Release::class, null);
            }

            return 'leaf';
        });
        $callbackLeafHolderSource = $this->leafCallbackParent($mapper, $callbackLeafSource, $target, $nested);
        $mapper->warmup();

        try {
            $mapper->map($callbackLeafHolderSource, CallbackLeafHolderDto::class);
            self::fail('Expected the leaf callback to fail.');
        } catch (MappingExecutionFailed $exception) {
            self::assertSame('Could not execute mapping ' . CallbackLeafHolderSource::class . '->' . CallbackLeafHolderDto::class . '.', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
        self::assertSame(['callback'], $collectionExecutionTrace->events);
        self::assertLeafCannotBorrowParentContext($cache);
        self::assertEquals([new ReleaseDto('sibling')], $mapper->map($callbackLeafHolderSource, CallbackLeafHolderDto::class)->releases);
        self::assertSame(['callback', 'callback'], $collectionExecutionTrace->events);
        self::assertLeafCannotBorrowParentContext($cache);
    }

    #[DataProvider('suspendedNestedLeafCallbackModes')]
    public function testSuspendedNestedLeafCallbacksResumeOrThrowWithoutLeakingContexts(bool $prepared, string $callbackKind): void
    {
        if (function_exists('xdebug_info') && in_array('develop', xdebug_info('mode'), true)) {
            self::markTestSkipped('Xdebug retains exception arguments; run XDEBUG_MODE=off to verify runtime retention.');
        }
        [$mapper, $cache, $target] = $this->leafCallbackRuntime($prepared, $callbackKind);
        $mapper->warmup();

        foreach (['resume', 'throw'] as $continuation) {
            $trace                = new CollectionExecutionTrace();
            $callbackLeafSource   = new CallbackLeafSource(static function() use ($mapper, $trace): string {
                $trace->events[] = 'suspended';
                Fiber::suspend('nested-leaf');
                $trace->events[] = 'continued';
                self::assertEquals(new ReleaseDto('fiber-after'), $mapper->map(new Release('fiber-after'), ReleaseDto::class));

                return 'leaf';
            });
            $callbackLeafHolderSource     = new CallbackLeafHolderSource($callbackLeafSource, [new Release('sibling')]);
            $weakLeaf                     = WeakReference::create($callbackLeafSource);
            $weakSource                   = WeakReference::create($callbackLeafHolderSource);
            $fiber                        = new Fiber(static function() use ($mapper, $callbackLeafHolderSource): string {
                try {
                    $mapped = $mapper->map($callbackLeafHolderSource, CallbackLeafHolderDto::class);

                    self::assertInstanceOf(CallbackLeafDto::class, $mapped->leaf);

                    return $mapped->leaf->version;
                } catch (MappingExecutionFailed) {
                    self::assertEquals(new ReleaseDto('fiber-after-throw'), $mapper->map(new Release('fiber-after-throw'), ReleaseDto::class));

                    return 'caught';
                }
            });
            $weakFiber = WeakReference::create($fiber);

            self::assertSame('nested-leaf', $fiber->start());
            self::assertEquals(new ReleaseDto('main-while-suspended'), $mapper->map(new Release('main-while-suspended'), ReleaseDto::class));

            if ('resume' === $continuation) {
                $fiber->resume();
                self::assertSame('leaf', $fiber->getReturn());
                self::assertSame(['suspended', 'continued'], $trace->events);
            } else {
                $fiber->throw(new RuntimeException('sensitive resumed failure'));
                self::assertSame('caught', $fiber->getReturn());
                self::assertSame(['suspended'], $trace->events);
            }

            self::assertLeafCannotBorrowParentContext($cache);
            self::assertEquals(new ReleaseDto('main-after-continuation'), $mapper->map(new Release('main-after-continuation'), ReleaseDto::class));
            unset($callbackLeafSource, $callbackLeafHolderSource, $fiber);
            self::assertNull($weakFiber->get());
            self::assertNull($weakLeaf->get());
            self::assertNull($weakSource->get());
        }
    }

    #[DataProvider('collectionCacheModes')]
    public function testPublicCacheReentryRestoresOuterMappingAfterExecutionAndPreparationFailures(bool $prepared): void
    {
        [$mapper, $cache, $target] = $this->leafCallbackRuntime($prepared, 'getter');
        $mapper->warmup();
        $collectionExecutionTrace       = new CollectionExecutionTrace();
        $mappingDefinition              = new MappingDefinition(FiberCollectionFailureSource::class, FiberCollectionFailureDto::class, [
            'releases' => MapRule::fromGetter('getReleases')->collection(Release::class, ReleaseDto::class),
        ]);
        $callbackLeafHolderSource = new CallbackLeafHolderSource(new CallbackLeafSource(static fn (): string => 'outer-leaf'), [new Release('outer')], static function() use ($cache, $mappingDefinition, $collectionExecutionTrace): void {
            try {
                $cache->map($mappingDefinition, new FiberCollectionFailureSource($cache, false, 41));
            } catch (MappingExecutionFailed $exception) {
                self::assertStringContainsString('integer key 41', $exception->getMessage());
                $collectionExecutionTrace->events[] = 'inner-execution';
            }

            try {
                $cache->map(new MappingDefinition(DefaultSource::class, MissingTarget::class), new DefaultSource(1));
            } catch (MappingCompilationFailed) {
                $collectionExecutionTrace->events[] = 'partial-preparation';
            }
        });

        $callbackLeafHolderDto = $mapper->map($callbackLeafHolderSource, CallbackLeafHolderDto::class);

        self::assertSame(['inner-execution', 'partial-preparation'], $collectionExecutionTrace->events);
        self::assertSame('outer-leaf', $callbackLeafHolderDto->leaf?->version);
        self::assertEquals([new ReleaseDto('outer')], $callbackLeafHolderDto->releases);
        self::assertLeafCannotBorrowParentContext($cache);
        self::assertSame('recovered', $mapper->map(new CallbackLeafSource(static fn (): string => 'recovered'), $target)->version);
    }

    public function testFixedPreparedRegistryReusesStructuralScopeTables(): void
    {
        $child  = new MappingDefinition(ExactChild::class, ExactChildDto::class);
        $parent = new MappingDefinition(ExactHolderSource::class, ExactHolderDto::class, [
            'child' => MapRule::from('child')->nested(ExactChildDto::class),
        ]);
        [$mapper, $mapperCache, $mappingMetadataFactory] = $this->fixedPreparedRuntime($child, $parent);

        $mapper->warmup();
        self::assertSame('a', $mapper->map(new ExactHolderSource(new ExactChild('a')), ExactHolderDto::class)->child->value);
        self::assertSame('b', $mapper->map(new ExactHolderSource(new ExactChild('b')), ExactHolderDto::class)->child->value);

        $metadata           = $mapperCache->metadata($parent);
        $reflectionProperty = new ReflectionProperty(MappingMetadataFactory::class, 'dependencySnapshots');
        $reflectionProperty->setValue($mappingMetadataFactory, new WeakMap());

        $reflectionMethod = new ReflectionMethod(MapperCache::class, 'scopeMappings');

        try {
            $reflectionMethod->invoke($mapperCache, $parent, new MappingExecution(), $metadata);
            self::fail('Expected rebuilt scope validation to fail once dependency snapshots are gone.');
        } catch (MappingCompilationFailed) {
            self::addToAssertionCount(1);
        }

        self::assertSame('c', $mapper->map(new ExactHolderSource(new ExactChild('c')), ExactHolderDto::class)->child->value);
    }

    public function testCustomRegistryStillValidatesEveryPreparedRoot(): void
    {
        $child  = new MappingDefinition(ExactChild::class, ExactChildDto::class);
        $parent = new MappingDefinition(ExactHolderSource::class, ExactHolderDto::class, [
            'child' => MapRule::from('child')->nested(ExactChildDto::class),
        ]);
        $swappable       = new SwappableDependencyRegistry(new MappingRegistry([$child, $parent]), ExactChild::class, ExactChildDto::class);
        $objectMapper    = $this->mapperWithRegistry($swappable);

        self::assertSame('a', $objectMapper->map(new ExactHolderSource(new ExactChild('a')), ExactHolderDto::class)->child->value);

        $swappable->replacement = new MappingDefinition(ExactChild::class, ExactChildDto::class);
        self::assertMapRejected($objectMapper, new ExactHolderSource(new ExactChild('b')));

        $childTwo  = new MappingDefinition(ExactChild::class, ExactChildDto::class);
        $parentTwo = new MappingDefinition(ExactHolderSource::class, ExactHolderDto::class, [
            'child' => MapRule::from('child')->nested(ExactChildDto::class),
        ]);
        $swappableTwo = new SwappableDependencyRegistry(new MappingRegistry([$childTwo, $parentTwo]), ExactChild::class, ExactChildDto::class);
        $mapperTwo    = $this->mapperWithRegistry($swappableTwo);

        $mapperTwo->map(new ExactHolderSource(new ExactChild('a')), ExactHolderDto::class);
        $swappableTwo->replacement = new MappingDefinition(ExactChild::class, ExactChildDto::class, sourceMatch: SourceMatchMode::CycleProxy);
        self::assertMapRejected($mapperTwo, new ExactHolderSource(new ExactChild('b')));

        $leaf   = new MappingDefinition(ThreeLevelLeafSource::class, ThreeLevelLeafDto::class);
        $middle = new MappingDefinition(ThreeLevelMiddleSource::class, ThreeLevelMiddleDto::class, [
            'child' => MapRule::from('child')->nested(ThreeLevelLeafDto::class),
        ]);
        $root = new MappingDefinition(ThreeLevelRootSource::class, ThreeLevelRootDto::class, [
            'child' => MapRule::from('child')->nested(ThreeLevelMiddleDto::class),
        ]);
        $swappableThree = new SwappableDependencyRegistry(new MappingRegistry([$leaf, $middle, $root]), ThreeLevelLeafSource::class, ThreeLevelLeafDto::class);
        $mapperThree    = $this->mapperWithRegistry($swappableThree);

        $mapperThree->map(new ThreeLevelRootSource(new ThreeLevelMiddleSource(new ThreeLevelLeafSource('a'))), ThreeLevelRootDto::class);
        $swappableThree->replacement = new MappingDefinition(ThreeLevelLeafSource::class, ThreeLevelLeafDto::class);
        self::assertMapRejected($mapperThree, new ThreeLevelRootSource(new ThreeLevelMiddleSource(new ThreeLevelLeafSource('b'))), ThreeLevelRootDto::class);
    }

    public function testPreparedScopesNeverReuseExecutionProvenance(): void
    {
        $release    = new MappingDefinition(Release::class, ReleaseDto::class);
        $collection = new MappingDefinition(ReleaseCollectionSource::class, ReleaseCollectionDto::class, [
            'releases' => MapRule::from('releases')->collection(Release::class, ReleaseDto::class),
        ]);
        [$mapper, $mapperCache] = $this->fixedPreparedRuntime($release, $collection);

        $mapper->warmup();
        $mapper->map(new ReleaseCollectionSource([new Release('1')]), ReleaseCollectionDto::class);

        try {
            $createInvalidCollection = (static fn (mixed $releases): ReleaseCollectionSource => new ReleaseCollectionSource($releases));
            $mapper->map($createInvalidCollection([new stdClass()]), ReleaseCollectionDto::class);
            self::fail('Expected an invalid element to fail.');
        } catch (MappingExecutionFailed $exception) {
            self::assertSame(MappingFailureReason::CollectionElementType, $exception->reason());
        }

        $tables = (new ReflectionProperty(MapperCache::class, 'preparedScopeTables'))->getValue($mapperCache);
        self::assertInstanceOf(WeakMap::class, $tables);
        foreach ($tables as $table) {
            self::assertFalse($this->containsExecutionState($table), 'A reused scope table must not retain execution state.');
        }

        self::assertSame([], $mapper->map(new ReleaseCollectionSource([]), ReleaseCollectionDto::class)->releases);

        [$replayMapper, $replayCache]                   = $this->leafCallbackRuntime(true, 'getter');
        $captured                                       = null;
        $callbackLeafHolderSource                       = new CallbackLeafHolderSource(
            new CallbackLeafSource(static function() use (&$captured): never {
                self::assertInstanceOf(GeneratedMappingExecutionFailed::class, $captured);

                throw $captured;
            }),
            [],
            static function() use ($replayCache, &$captured): void {
                try {
                    $replayCache->collectionElementTypeFailure(CallbackLeafHolderSource::class, CallbackLeafHolderDto::class, 'releases', 7, Release::class, new AccessToken('replay-secret'));
                } catch (GeneratedMappingExecutionFailed $exception) {
                    $captured = $exception;
                }
            },
        );
        $replayMapper->warmup();

        try {
            $replayMapper->map($callbackLeafHolderSource, CallbackLeafHolderDto::class);
            self::fail('Expected the captured collection failure to be sanitized during replay.');
        } catch (MappingExecutionFailed $exception) {
            self::assertSame(MappingFailureReason::GeneratedMappingFailed, $exception->reason());
            self::assertNull($exception->getPrevious());
        }

        $fiber = new Fiber(static fn (): object => $replayMapper->map(
            new CallbackLeafHolderSource(new CallbackLeafSource(static function() use (&$captured): never {
                self::assertInstanceOf(GeneratedMappingExecutionFailed::class, $captured);

                throw $captured;
            }), []),
            CallbackLeafHolderDto::class,
        ));

        try {
            $fiber->start();
            self::fail('Expected the Fiber replay to be sanitized.');
        } catch (MappingExecutionFailed $exception) {
            self::assertSame(MappingFailureReason::GeneratedMappingFailed, $exception->reason());
            self::assertNull($exception->getPrevious());
        }

        $replayTables = (new ReflectionProperty(MapperCache::class, 'preparedScopeTables'))->getValue($replayCache);
        self::assertInstanceOf(WeakMap::class, $replayTables);
        foreach ($replayTables as $replayTable) {
            self::assertFalse($this->containsExecutionState($replayTable), 'A reused scope table must not retain execution state after replay.');
        }
    }

    public function testPreparedScopesDoNotKeepReleasedRootDefinitionsOrInputs(): void
    {
        $child                    = new MappingDefinition(ExactChild::class, ExactChildDto::class);
        $mappingRegistry          = new MappingRegistry([$child]);
        $valueTransformerRegistry = new ValueTransformerRegistry();
        $mapperCache              = new MapperCache(
            new MappingMetadataFactory($valueTransformerRegistry, mappingRegistry: $mappingRegistry),
            new PhpMapperGenerator(),
            $this->cacheDirectory,
            $valueTransformerRegistry,
            true,
            $mappingRegistry,
            reusePreparedMappings: true,
        );

        $root = new MappingDefinition(ExactHolderSource::class, ExactHolderDto::class, [
            'child' => MapRule::from('child')->nested(ExactChildDto::class),
        ]);
        $weakReference         = WeakReference::create($root);
        $exactHolderSource     = new ExactHolderSource(new ExactChild('released'));
        $weakSource            = WeakReference::create($exactHolderSource);
        $result                = $mapperCache->map($root, $exactHolderSource);
        $weakResult            = WeakReference::create($result);

        unset($root, $exactHolderSource, $result);
        gc_collect_cycles();

        self::assertNull($weakReference->get());
        self::assertNull($weakSource->get());
        self::assertNull($weakResult->get());
    }

    public function testItTreatsPublicCacheMappingAndUnrelatedNestedDispatchAsIndependent(): void
    {
        $mappingDefinition = new MappingDefinition(UnrelatedChildSource::class, ReleaseCollectionDto::class, [
            'releases' => MapRule::from('releases')->collection(Release::class, ReleaseDto::class),
        ]);
        $definitions = [
            new MappingDefinition(Release::class, ReleaseDto::class),
            $mappingDefinition,
            new MappingDefinition(CacheMapFailureSource::class, IndependentCollectionFailureDto::class, [
                'releases' => MapRule::fromGetter('getReleases')->collection(Release::class, ReleaseDto::class),
            ]),
            new MappingDefinition(UnrelatedNestedDispatchSource::class, FiberCollectionFailureDto::class, [
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

        foreach ([
            [new CacheMapFailureSource($mapperCache, $mappingDefinition), IndependentCollectionFailureDto::class],
            [new UnrelatedNestedDispatchSource($mapperCache), FiberCollectionFailureDto::class],
        ] as [$source, $target]) {
            try {
                $objectMapper->map($source, $target);
                self::fail('Expected unrelated dispatch to be sanitized.');
            } catch (MappingExecutionFailed $exception) {
                self::assertStringNotContainsString(UnrelatedChildSource::class . '->' . ReleaseCollectionDto::class, $exception->getMessage());
                self::assertStringNotContainsString('attacker-secret', $exception->getMessage());
            }
        }
    }

    public function testPreparedCacheMapsWarmedSimpleNestedAndCollectionMappings(): void
    {
        $definitions = [
            new MappingDefinition(Release::class, ReleaseDto::class),
            new MappingDefinition(ReleaseCollectionSource::class, ReleaseCollectionDto::class, [
                'releases' => MapRule::from('releases')->collection(Release::class, ReleaseDto::class),
            ]),
            new MappingDefinition(NestedReleaseCollectionSource::class, NestedReleaseCollectionDto::class, [
                'collection' => MapRule::from('collection')->nested(ReleaseCollectionDto::class),
            ]),
        ];
        $objectMapper = $this->mapperWithPreparedCache(false, true, ...$definitions);

        self::assertSame([
            Release::class . '->' . ReleaseDto::class,
            ReleaseCollectionSource::class . '->' . ReleaseCollectionDto::class,
            NestedReleaseCollectionSource::class . '->' . NestedReleaseCollectionDto::class,
        ], $objectMapper->warmup());

        $releaseDto                 = $objectMapper->map(new Release('simple'), ReleaseDto::class);
        $nestedReleaseCollectionDto = $objectMapper->map(
            new NestedReleaseCollectionSource(new ReleaseCollectionSource([new Release('nested')])),
            NestedReleaseCollectionDto::class,
        );

        self::assertSame('simple', $releaseDto->version);
        self::assertSame('nested', $nestedReleaseCollectionDto->collection->releases[0]->version);
    }

    #[DataProvider('collectionCacheModes')]
    public function testLeafSourceValidationRunsOnEveryRootAndNestedInvocation(bool $reusePreparedMappings): void
    {
        foreach ([SourceMatchMode::Exact, SourceMatchMode::CycleProxy] as $mode) {
            RecordingCycleProxyTransformer::$invocations = 0;
            $child                                       = new MappingDefinition(CycleProxyEntity::class, CycleProxyEntityDto::class, [
                'id' => MapRule::from('id')->through(RecordingCycleProxyTransformer::class),
            ], sourceMatch: $mode);
            $registry = new MappingRegistry([
                $child,
                new MappingDefinition(CycleProxyHolder::class, CycleProxyHolderDto::class, [
                    'child' => MapRule::from('child')->nested(CycleProxyEntityDto::class),
                ]),
            ]);
            $transformers = new ValueTransformerRegistry([new RecordingCycleProxyTransformer()]);
            $cache        = new MapperCache(
                new MappingMetadataFactory($transformers, mappingRegistry: $registry),
                new PhpMapperGenerator(), $this->cacheDirectory, $transformers,
                generateOnDemand: true, mappingRegistry: $registry, reusePreparedMappings: $reusePreparedMappings,
            );
            $mapper = new ObjectMapper($registry, $cache);
            $mapper->warmup();
            self::assertSame(0, RecordingCycleProxyTransformer::$invocations);
            $expectedInvocations = 0;

            foreach ([false, true] as $nested) {
                $map = static fn (CycleProxyEntity $cycleProxyEntity): object => $nested
                    ? $mapper->map(new CycleProxyHolder($cycleProxyEntity), CycleProxyHolderDto::class)->child
                    : $cache->map($child, $cycleProxyEntity);
                self::assertEquals(new CycleProxyEntityDto(1), $map(new CycleProxyEntity(1)));
                ++$expectedInvocations;
                $rejected = [new NormalCycleEntitySubclass(2), new IndirectCycleProxy(3)];
                if (SourceMatchMode::Exact === $mode) {
                    $rejected[] = new DirectCycleProxy(4);
                } else {
                    self::assertEquals(new CycleProxyEntityDto(4), $map(new DirectCycleProxy(4)));
                    ++$expectedInvocations;
                }

                foreach ($rejected as $source) {
                    try {
                        $map($source);
                        self::fail('Expected the warmed leaf to reject ' . $source::class);
                    } catch (MappingExecutionFailed $exception) {
                        self::assertNull($exception->getPrevious());
                    }
                    self::assertSame($expectedInvocations, RecordingCycleProxyTransformer::$invocations);
                }
                self::assertEquals(new CycleProxyEntityDto(5), $map(new CycleProxyEntity(5)));
                self::assertSame(++$expectedInvocations, RecordingCycleProxyTransformer::$invocations);
            }
        }
    }

    #[DataProvider('collectionCacheModes')]
    public function testLeafCacheDoesNotRetainReleasedDefinitions(bool $reusePreparedMappings): void
    {
        $mapperCache = new MapperCache(
            new MappingMetadataFactory(), new PhpMapperGenerator(), $this->cacheDirectory,
            new ValueTransformerRegistry(), generateOnDemand: true, reusePreparedMappings: $reusePreparedMappings,
        );
        foreach (['first', 'second'] as $version) {
            $mappingDefinition = new MappingDefinition(Release::class, ReleaseDto::class, [
                'version' => MapRule::constant($version),
            ], ['version']);
            $reference = WeakReference::create($mappingDefinition);
            self::assertEquals(new ReleaseDto($version), $mapperCache->map($mappingDefinition, new Release('source')));
            unset($mappingDefinition);
            self::assertNull($reference->get());
        }
    }

    #[DataProvider('collectionCacheModes')]
    public function testLeafSourceFileChangesInvalidateOnlyDefaultCacheEntries(bool $reusePreparedMappings): void
    {
        self::assertTrue(mkdir($this->cacheDirectory, 0o700, true));
        $class = 'LiveLeafSource' . bin2hex(random_bytes(8));
        $path  = $this->cacheDirectory . '/source.php';
        $code  = '<?php final class ' . $class . ' { public string $version = "live"; } return new ' . $class . '();';
        self::assertSame(strlen($code), file_put_contents($path, $code));
        $source = require $path;
        self::assertIsObject($source);
        $definition = new MappingDefinition($source::class, ReleaseDto::class);
        $mapper     = $this->mapperWithPreparedCache(true, $reusePreparedMappings, $definition);
        self::assertEquals(new ReleaseDto('live'), $mapper->map($source, ReleaseDto::class));
        self::assertCount(1, glob($this->cacheDirectory . '/Mapper_*.php') ?: []);

        // Change the reflected file's content without redefining a loaded PHP class.
        $code .= ' // revised source fingerprint';
        self::assertSame(strlen($code), file_put_contents($path, $code));
        self::assertEquals(new ReleaseDto('live'), $mapper->map($source, ReleaseDto::class));
        self::assertCount($reusePreparedMappings ? 1 : 2, glob($this->cacheDirectory . '/Mapper_*.php') ?: []);

        $replacement = $this->mapperWithPreparedCache(true, $reusePreparedMappings, $definition);
        self::assertEquals(new ReleaseDto('live'), $replacement->map($source, ReleaseDto::class));
        self::assertCount(2, glob($this->cacheDirectory . '/Mapper_*.php') ?: []);
    }

    public function testPreparedCacheDoesNotRepeatMetadataPreparationForTheSameDefinition(): void
    {
        [$objectMapper, $countingValueTransformerRegistry] = $this->mapperWithCountingTransformer(true);

        $objectMapper->warmup();
        $objectMapper->map(new ConventionalSource(1, 'first', true), ConventionalTarget::class);
        $objectMapper->map(new ConventionalSource(2, 'second', false), ConventionalTarget::class);

        // The warmup metadata compilation reads the transformer once; each
        // generated mapper execution reads it once. A second metadata/cache-key
        // preparation would make one additional registry read per mapping.
        self::assertSame(3, $countingValueTransformerRegistry->getCalls);
    }

    public function testDefaultCacheModeRepeatsMetadataPreparationForTheSameDefinition(): void
    {
        [$objectMapper, $countingValueTransformerRegistry] = $this->mapperWithCountingTransformer(false);

        $objectMapper->warmup();
        $objectMapper->map(new ConventionalSource(1, 'first', true), ConventionalTarget::class);
        $objectMapper->map(new ConventionalSource(2, 'second', false), ConventionalTarget::class);

        // The warmup metadata compilation and each generated mapper execution
        // read the transformer once. The default path also recompiles metadata
        // and the generated-cache key for each map() call.
        self::assertSame(5, $countingValueTransformerRegistry->getCalls);
    }

    public function testPreparedCacheDoesNotReuseAnEntryForANewRootDefinitionIdentity(): void
    {
        $freshRootDefinitionRegistry = new FreshRootDefinitionRegistry();
        $valueTransformerRegistry    = new ValueTransformerRegistry();
        $objectMapper                = new ObjectMapper(
            $freshRootDefinitionRegistry,
            new MapperCache(
                new MappingMetadataFactory($valueTransformerRegistry, mappingRegistry: $freshRootDefinitionRegistry),
                new PhpMapperGenerator(),
                $this->cacheDirectory,
                $valueTransformerRegistry,
                true,
                $freshRootDefinitionRegistry,
                reusePreparedMappings: true,
            ),
        );

        self::assertSame('first', $objectMapper->map(new FreshRootDefinitionSource('source'), FreshRootDefinitionTarget::class)->value);
        self::assertSame('second', $objectMapper->map(new FreshRootDefinitionSource('source'), FreshRootDefinitionTarget::class)->value);
    }

    public function testDefaultCacheModeAlsoResolvesEachFreshRootDefinition(): void
    {
        $freshRootDefinitionRegistry = new FreshRootDefinitionRegistry();
        $valueTransformerRegistry    = new ValueTransformerRegistry();
        $objectMapper                = new ObjectMapper(
            $freshRootDefinitionRegistry,
            new MapperCache(
                new MappingMetadataFactory($valueTransformerRegistry, mappingRegistry: $freshRootDefinitionRegistry),
                new PhpMapperGenerator(),
                $this->cacheDirectory,
                $valueTransformerRegistry,
                true,
                $freshRootDefinitionRegistry,
            ),
        );

        self::assertSame('first', $objectMapper->map(new FreshRootDefinitionSource('source'), FreshRootDefinitionTarget::class)->value);
        self::assertSame('second', $objectMapper->map(new FreshRootDefinitionSource('source'), FreshRootDefinitionTarget::class)->value);
    }

    public function testPreparedCacheRetainsNestedDependencyIdentityValidation(): void
    {
        $mappingDefinition          = new MappingDefinition(Release::class, ReleaseDto::class);
        $statefulDependencyRegistry = new StatefulDependencyRegistry(
            new MappingRegistry([
                $mappingDefinition,
                new MappingDefinition(ReleaseCollectionSource::class, ReleaseCollectionDto::class, [
                    'releases' => MapRule::from('releases')->collection(Release::class, ReleaseDto::class),
                ]),
            ]),
            Release::class,
            ReleaseDto::class,
            new MappingDefinition(Release::class, ReleaseDto::class),
        );
        $valueTransformerRegistry = new ValueTransformerRegistry();
        $objectMapper             = new ObjectMapper(
            $statefulDependencyRegistry,
            new MapperCache(
                new MappingMetadataFactory($valueTransformerRegistry, mappingRegistry: $statefulDependencyRegistry),
                new PhpMapperGenerator(),
                $this->cacheDirectory,
                $valueTransformerRegistry,
                true,
                $statefulDependencyRegistry,
                reusePreparedMappings: true,
            ),
        );

        $this->expectException(MappingCompilationFailed::class);
        $this->expectExceptionMessage('Nested mapping dependency does not match its compiled definition.');
        $objectMapper->map(new ReleaseCollectionSource([new Release('version')]), ReleaseCollectionDto::class);
    }

    public function testPreparedCacheBypassesCustomAndProviderCustomMappings(): void
    {
        $recordingCustomChildMapper    = new RecordingCustomChildMapper();
        $recordingCustomMapperProvider = new RecordingCustomMapperProvider([
            'provider' => new RecordingProviderCustomMapper(),
        ]);
        $objectMapper = $this->mapperWithPreparedCacheAndProvider(
            true,
            $recordingCustomMapperProvider,
            new CustomMappingDefinition(CustomChildSource::class, CustomChildDto::class, $recordingCustomChildMapper),
            new ProviderCustomMappingDefinition(Release::class, ReleaseDto::class, 'provider'),
        );

        $objectMapper->warmup();
        $objectMapper->map(new CustomChildSource('custom'), CustomChildDto::class);
        $objectMapper->map(new CustomChildSource('custom'), CustomChildDto::class);
        $objectMapper->map(new Release('provider'), ReleaseDto::class);
        $objectMapper->map(new Release('provider'), ReleaseDto::class);

        self::assertSame(2, $recordingCustomChildMapper->invocations);
        self::assertSame(2, $recordingCustomMapperProvider->lookups);
    }

    #[DataProvider('leafReplayModes')]
    public function testLeafRejectsReplayedParentGeneratedFailure(bool $prepared, string $callbackKind, bool $nested, bool $consume): void
    {
        [$mapper, $cache, $target]                    = $this->leafCallbackRuntime($prepared, $callbackKind);
        $mappingDefinition                            = new MappingDefinition(CallbackLeafHolderSource::class, CallbackLeafHolderDto::class);
        $captured                                     = null;
        $collectionExecutionTrace                     = new CollectionExecutionTrace();
        $leaf                                         = new CallbackLeafSource(static function() use ($cache, $mappingDefinition, &$captured, $collectionExecutionTrace, $consume): never {
            self::assertInstanceOf(GeneratedMappingExecutionFailed::class, $captured);
            if ($consume) {
                self::assertNull($cache->collectionFailure($captured, $mappingDefinition));
            }
            $collectionExecutionTrace->events[] = 'replayed-parent-context';

            throw $captured;
        });
        $callbackLeafHolderSource = new CallbackLeafHolderSource($nested ? $leaf : null, [], static function() use ($mapper, $cache, $leaf, $target, $nested, &$captured, $collectionExecutionTrace): void {
            try {
                $cache->collectionElementTypeFailure(CallbackLeafHolderSource::class, CallbackLeafHolderDto::class, 'releases', 23, Release::class, new AccessToken('replay-secret'));
            } catch (GeneratedMappingExecutionFailed $exception) {
                $captured                           = $exception;
                $collectionExecutionTrace->events[] = 'captured';
            }
            if (! $nested) {
                $mapper->map($leaf, $target);
            }
        });
        $mapper->warmup();

        try {
            $mapper->map($callbackLeafHolderSource, CallbackLeafHolderDto::class);
            self::fail('Expected the replayed failure to be sanitized.');
        } catch (MappingExecutionFailed $exception) {
            self::assertSame(['captured', 'replayed-parent-context'], $collectionExecutionTrace->events);
            self::assertSame('Could not execute mapping ' . $mappingDefinition->key() . '.', $exception->getMessage());
            self::assertSame(MappingFailureReason::GeneratedMappingFailed, $exception->reason());
            self::assertNull($exception->getPrevious());
        }
        self::assertInstanceOf(GeneratedMappingExecutionFailed::class, $captured);
        $replay = new CallbackLeafSource(static function() use ($captured): never {
            throw $captured;
        });

        try {
            $mapper->map($replay, $target);
            self::fail('Expected replay into a later root to remain sanitized.');
        } catch (MappingExecutionFailed $exception) {
            self::assertSame('Could not execute mapping ' . CallbackLeafSource::class . '->' . $target . '.', $exception->getMessage());
            self::assertSame(MappingFailureReason::GeneratedMappingFailed, $exception->reason());
            self::assertNull($exception->getPrevious());
        }
        self::assertLeafCannotBorrowParentContext($cache);
        self::assertSame('recovered', $mapper->map(new CallbackLeafSource(static fn (): string => 'recovered'), $target)->version);
    }

    #[DataProvider('collectionCacheModes')]
    public function testCollectionLeafRejectsUnconsumedParentGeneratedFailure(bool $reusePreparedMappings): void
    {
        [$mapper, $cache]                           = $this->collectionCallbackRuntime(reusePreparedMappings: $reusePreparedMappings);
        $captured                                   = null;
        $collectionExecutionTrace                   = new CollectionExecutionTrace();
        $observedReleaseCollectionsSource           = new ObservedReleaseCollectionsSource([
            new CallbackRelease(static function() use (&$captured, $collectionExecutionTrace): never {
                self::assertInstanceOf(GeneratedMappingExecutionFailed::class, $captured);
                $collectionExecutionTrace->events[] = 'replayed';

                throw $captured;
            }),
        ], [], $collectionExecutionTrace, static function() use ($cache, &$captured, $collectionExecutionTrace): void {
            try {
                $cache->collectionElementTypeFailure(ObservedReleaseCollectionsSource::class, ObservedReleaseCollectionsDto::class, 'second', 29, Release::class, new AccessToken('collection-replay-secret'));
            } catch (GeneratedMappingExecutionFailed $exception) {
                $captured                           = $exception;
                $collectionExecutionTrace->events[] = 'captured';
            }
        });
        $mapper->warmup();

        try {
            $mapper->map($observedReleaseCollectionsSource, ObservedReleaseCollectionsDto::class);
            self::fail('Expected the collection leaf to sanitize the unconsumed parent token.');
        } catch (MappingExecutionFailed $exception) {
            self::assertSame(['first', 'captured', 'replayed'], $collectionExecutionTrace->events);
            self::assertSame('Could not execute mapping ' . ObservedReleaseCollectionsSource::class . '->' . ObservedReleaseCollectionsDto::class . '.', $exception->getMessage());
            self::assertSame(MappingFailureReason::GeneratedMappingFailed, $exception->reason());
            self::assertNull($exception->getPrevious());
        }
        $observedReleaseCollectionsDto = $mapper->map(new ObservedReleaseCollectionsSource([
            new CallbackRelease(static fn (): string => 'recovered'),
        ], [new Release('sibling')], new CollectionExecutionTrace()), ObservedReleaseCollectionsDto::class);
        self::assertEquals([new ReleaseDto('recovered')], $observedReleaseCollectionsDto->first);
        self::assertEquals([new ReleaseDto('sibling')], $observedReleaseCollectionsDto->second);
    }

    #[DataProvider('leafReentryModes')]
    public function testLeafFibersReleaseInputsAfterThrowOrAbandonment(bool $prepared, string $callbackKind, bool $nested): void
    {
        if (function_exists('xdebug_info') && in_array('develop', xdebug_info('mode'), true)) {
            self::markTestSkipped('Xdebug retains exception arguments; run XDEBUG_MODE=off to verify runtime retention.');
        }
        [$mapper, $cache, $target] = $this->leafCallbackRuntime($prepared, $callbackKind);
        $mapper->warmup();

        foreach (['throw', 'abandon'] as $termination) {
            $trace               = new CollectionExecutionTrace();
            $callbackLeafSource  = new CallbackLeafSource(static function() use ($trace): string {
                try {
                    Fiber::suspend('leaf');

                    return 'unexpected-resume';
                } finally {
                    $trace->events[] = 'unwound';
                }
            });
            $source     = $this->leafCallbackParent($mapper, $callbackLeafSource, $target, $nested);
            $weakLeaf   = WeakReference::create($callbackLeafSource);
            $weakSource = WeakReference::create($source);
            $fiber      = new Fiber(static fn (): CallbackLeafHolderDto => $mapper->map($source, CallbackLeafHolderDto::class));
            $weakFiber  = WeakReference::create($fiber);
            self::assertSame('leaf', $fiber->start());
            unset($callbackLeafSource, $source);
            self::assertNotNull($weakLeaf->get());
            self::assertNotNull($weakSource->get());

            if ('throw' === $termination) {
                try {
                    $fiber->throw(new RuntimeException('sensitive injected failure'));
                    self::fail('Expected the injected Fiber failure to escape as a safe mapping error.');
                } catch (MappingExecutionFailed $exception) {
                    self::assertSame('Could not execute mapping ' . CallbackLeafHolderSource::class . '->' . CallbackLeafHolderDto::class . '.', $exception->getMessage());
                    self::assertNull($exception->getPrevious());
                }
                self::assertTrue($fiber->isTerminated());
                unset($exception);
            }
            unset($fiber);
            self::assertSame(['unwound'], $trace->events);
            self::assertNull($weakFiber->get());
            self::assertNull($weakLeaf->get());
            self::assertNull($weakSource->get());
            self::assertLeafCannotBorrowParentContext($cache);
            $recovered = $this->leafCallbackParent($mapper, new CallbackLeafSource(static fn (): string => 'leaf'), $target, $nested);
            self::assertEquals([new ReleaseDto('sibling')], $mapper->map($recovered, CallbackLeafHolderDto::class)->releases);
        }
    }
}
