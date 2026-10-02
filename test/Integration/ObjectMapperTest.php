<?php

declare(strict_types=1);

namespace Sirix\ObjectMapperTest\Integration;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhp;
use Sirix\ObjectMapper\Definition\CustomMappingDefinition;
use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Definition\MapRule;
use Sirix\ObjectMapper\Definition\SourceMatchMode;
use Sirix\ObjectMapper\Exception\MappingCompilationFailed;
use Sirix\ObjectMapper\Exception\MappingExecutionFailed;
use Sirix\ObjectMapper\Exception\MappingNotRegistered;
use Sirix\ObjectMapper\Generator\MapperCache;
use Sirix\ObjectMapper\Generator\PhpMapperGenerator;
use Sirix\ObjectMapper\Metadata\MappingMetadataFactory;
use Sirix\ObjectMapper\Runtime\MappingRegistry;
use Sirix\ObjectMapper\Runtime\ObjectMapper;
use Sirix\ObjectMapper\Runtime\ValueTransformerRegistry;
use Sirix\ObjectMapperTest\Support\AccessToken;
use Sirix\ObjectMapperTest\Support\ApiAccessTokenDto;
use Sirix\ObjectMapperTest\Support\BackedSetOnlySource;
use Sirix\ObjectMapperTest\Support\ByReferenceRecordingTransformer;
use Sirix\ObjectMapperTest\Support\ByReferenceRequiredTarget;
use Sirix\ObjectMapperTest\Support\ByReferenceTargetSource;
use Sirix\ObjectMapperTest\Support\ConstantSource;
use Sirix\ObjectMapperTest\Support\ConstantTarget;
use Sirix\ObjectMapperTest\Support\ConventionalSource;
use Sirix\ObjectMapperTest\Support\ConventionalTarget;
use Sirix\ObjectMapperTest\Support\CustomChildDto;
use Sirix\ObjectMapperTest\Support\CustomChildSource;
use Sirix\ObjectMapperTest\Support\CycleProxyCollectionHolder;
use Sirix\ObjectMapperTest\Support\CycleProxyCollectionHolderDto;
use Sirix\ObjectMapperTest\Support\CycleProxyEntity;
use Sirix\ObjectMapperTest\Support\CycleProxyEntityDto;
use Sirix\ObjectMapperTest\Support\DateTimeToAtomTransformer;
use Sirix\ObjectMapperTest\Support\DefaultSource;
use Sirix\ObjectMapperTest\Support\DefaultTarget;
use Sirix\ObjectMapperTest\Support\DirectCycleProxy;
use Sirix\ObjectMapperTest\Support\ExplicitMethodSource;
use Sirix\ObjectMapperTest\Support\ExplicitMethodTarget;
use Sirix\ObjectMapperTest\Support\HookValueTarget;
use Sirix\ObjectMapperTest\Support\NameTarget;
use Sirix\ObjectMapperTest\Support\ProfileTarget;
use Sirix\ObjectMapperTest\Support\RecordingCustomChildMapper;
use Sirix\ObjectMapperTest\Support\RecordingCustomMapperProvider;
use Sirix\ObjectMapperTest\Support\Release;
use Sirix\ObjectMapperTest\Support\ReleaseCollectionDto;
use Sirix\ObjectMapperTest\Support\ReleaseCollectionSource;
use Sirix\ObjectMapperTest\Support\ReleaseDto;
use Sirix\ObjectMapperTest\Support\RulePrecedenceSource;
use Sirix\ObjectMapperTest\Support\SameNamedConstantSource;
use Sirix\ObjectMapperTest\Support\ScalarConstantsTarget;
use Sirix\ObjectMapperTest\Support\ThreeLevelLeafDto;
use Sirix\ObjectMapperTest\Support\ThreeLevelLeafSource;
use Sirix\ObjectMapperTest\Support\ThreeLevelMiddleDto;
use Sirix\ObjectMapperTest\Support\ThreeLevelMiddleSource;
use Sirix\ObjectMapperTest\Support\ThreeLevelRootDto;
use Sirix\ObjectMapperTest\Support\ThreeLevelRootSource;
use Sirix\ObjectMapperTest\Support\ThrowingGetterSource;
use Sirix\ObjectMapperTest\Support\ThrowingTransformer;
use Sirix\ObjectMapperTest\Support\Uuid;
use Sirix\ObjectMapperTest\Support\VirtualGetOnlySource;
use Sirix\ObjectMapperTest\Support\VirtualGetSetSource;

use function bin2hex;
use function class_alias;
use function class_exists;
use function fileperms;
use function glob;
use function random_bytes;

#[CoversClass(ObjectMapper::class)]
final class ObjectMapperTest extends ObjectMapperIntegrationTestCase
{
    public function testItMapsARegisteredPairThroughGeneratedCode(): void
    {
        $mapper = $this->mapper(true, new MappingDefinition(ConventionalSource::class, ConventionalTarget::class));

        $conventionalTarget = $mapper->map(new ConventionalSource(7, 'Ada', true), ConventionalTarget::class);

        self::assertInstanceOf(ConventionalTarget::class, $conventionalTarget);
        self::assertSame(7, $conventionalTarget->id);
        self::assertSame('Ada', $conventionalTarget->name);
        self::assertTrue($conventionalTarget->active);
        $cacheFiles = glob($this->cacheDirectory . '/Mapper_*.php') ?: [];
        self::assertCount(1, $cacheFiles);
        self::assertSame(0o600, fileperms($cacheFiles[0]) & 0o777);
    }

    public function testItRejectsByReferenceTargetParametersBeforeReadingSource(): void
    {
        $byReferenceTargetSource            = new ByReferenceTargetSource();
        $mappingDefinition                  = new MappingDefinition(
            ByReferenceTargetSource::class,
            ByReferenceRequiredTarget::class,
            [
                'value' => MapRule::from('value')->through(ByReferenceRecordingTransformer::class),
            ],
        );
        $mappingRegistry                  = new MappingRegistry([$mappingDefinition]);
        $countingValueTransformerRegistry = new CountingValueTransformerRegistry(new ByReferenceRecordingTransformer());
        $objectMapper                     = new ObjectMapper(
            $mappingRegistry,
            new MapperCache(
                new MappingMetadataFactory($countingValueTransformerRegistry, mappingRegistry: $mappingRegistry),
                new PhpMapperGenerator(),
                $this->cacheDirectory,
                $countingValueTransformerRegistry,
                false,
                $mappingRegistry,
            ),
        );

        try {
            $objectMapper->warmup();
            self::fail('Expected the by-reference target constructor to be rejected.');
        } catch (MappingCompilationFailed $exception) {
            self::assertStringContainsString(ByReferenceTargetSource::class . ' -> ' . ByReferenceRequiredTarget::class, $exception->getMessage());
            self::assertStringContainsString('$value', $exception->getMessage());
            self::assertStringContainsString('By-reference target parameters are not supported.', $exception->getMessage());
        }

        self::assertSame(0, $countingValueTransformerRegistry->getCalls);
        self::assertSame(1, $byReferenceTargetSource->value);
        self::assertSame([], glob($this->cacheDirectory . '/Mapper_*.php') ?: []);
    }

    #[RequiresPhp('>= 8.4')]
    public function testItMapsReadableHookedProperties(): void
    {
        VirtualGetSetSource::$reads  = 0;
        $objectMapper                = $this->mapper(true, new MappingDefinition(VirtualGetSetSource::class, HookValueTarget::class));

        self::assertSame(3, $objectMapper->map(new VirtualGetSetSource(), HookValueTarget::class)->value);
        self::assertSame(1, VirtualGetSetSource::$reads);

        $getOnlyMapper = $this->mapper(true, new MappingDefinition(VirtualGetOnlySource::class, HookValueTarget::class));

        self::assertSame(42, $getOnlyMapper->map(new VirtualGetOnlySource(), HookValueTarget::class)->value);
    }

    #[RequiresPhp('>= 8.4')]
    public function testItKeepsBackedSetOnlyPropertiesReadable(): void
    {
        $mapper                     = $this->mapper(true, new MappingDefinition(BackedSetOnlySource::class, HookValueTarget::class));
        $backedSetOnlySource        = new BackedSetOnlySource();
        $backedSetOnlySource->value = 7;

        self::assertSame(7, $mapper->map($backedSetOnlySource, HookValueTarget::class)->value);
    }

    public function testItRetainsTheRuntimePairFailureWhenTheLogicalParentPairIsMissing(): void
    {
        $mapper = $this->mapper(true, new MappingDefinition(
            CycleProxyEntity::class,
            CycleProxyEntityDto::class,
            sourceMatch: SourceMatchMode::CycleProxy,
        ));

        $this->expectException(MappingNotRegistered::class);
        $mapper->map(new DirectCycleProxy(1), DefaultTarget::class);
    }

    public function testItPreservesTargetDefaultsAfterOnDemandGeneration(): void
    {
        $defaultTarget = $this->mapper(true, new MappingDefinition(DefaultSource::class, DefaultTarget::class))
            ->map(new DefaultSource(1), DefaultTarget::class)
        ;

        self::assertSame('default', $defaultTarget->label);
    }

    public function testItMapsTheConstantValueMatrixWithoutCollaborators(): void
    {
        $mappingDefinition = new MappingDefinition(
            ConstantSource::class,
            ScalarConstantsTarget::class,
            [
                'nullable' => MapRule::constant(null),
                'enabled'  => MapRule::constant(false),
                'rank'     => MapRule::constant(-7),
                'ratio'    => MapRule::constant(1.5),
                'label'    => MapRule::constant('configured'),
                'union'    => MapRule::constant(42),
            ],
        );
        $recordingCustomChildMapper     = new RecordingCustomChildMapper();
        $recordingCustomMapperProvider  = new RecordingCustomMapperProvider([]);
        $objectMapper                   = $this->mapperWithProvider(
            true,
            $recordingCustomMapperProvider,
            $mappingDefinition,
            new CustomMappingDefinition(CustomChildSource::class, CustomChildDto::class, $recordingCustomChildMapper),
        );

        $fresh = $objectMapper->map(new ConstantSource(), ScalarConstantsTarget::class);
        self::assertNull($fresh->nullable);
        self::assertFalse($fresh->enabled);
        self::assertSame(-7, $fresh->rank);
        self::assertSame(1.5, $fresh->ratio);
        self::assertSame('configured', $fresh->label);
        self::assertSame(42, $fresh->union);
        self::assertSame(0, $recordingCustomMapperProvider->lookups);
        self::assertSame(0, $recordingCustomChildMapper->invocations);

        $warmedMapper = $this->mapperWithProvider(
            false,
            $recordingCustomMapperProvider,
            $mappingDefinition,
            new CustomMappingDefinition(CustomChildSource::class, CustomChildDto::class, $recordingCustomChildMapper),
        );
        $warmedMapper->warmup();
        $scalarConstantsTarget = $warmedMapper->map(new ConstantSource(), ScalarConstantsTarget::class);
        self::assertEquals($fresh, $scalarConstantsTarget);
        self::assertSame(0, $recordingCustomMapperProvider->lookups);
        self::assertSame(0, $recordingCustomChildMapper->invocations);

        $withoutTransformer = $this->mapperWithTransformers(
            true,
            new ValueTransformerRegistry([new ThrowingTransformer()]),
            $mappingDefinition,
        )->map(new ConstantSource(), ScalarConstantsTarget::class);
        self::assertEquals($fresh, $withoutTransformer);

        $constantTarget = $this->mapper(true, new MappingDefinition(
            SameNamedConstantSource::class,
            ConstantTarget::class,
            [
                'value' => MapRule::constant('configured'),
            ],
            ['value'],
        ))->map(new SameNamedConstantSource('source value'), ConstantTarget::class);
        self::assertSame('configured', $constantTarget->value);
    }

    public function testItRejectsUnregisteredPairs(): void
    {
        $mapper = $this->mapper(true);

        $this->expectException(MappingNotRegistered::class);
        $mapper->map(new ConventionalSource(1, 'Ada', true), ConventionalTarget::class);
    }

    public function testItRejectsAliasesBeforeTheyCanCreateAnUnreachableExactPair(): void
    {
        $sourceAlias = 'SirixObjectMapperTestIntegrationAliasSource' . bin2hex(random_bytes(4));
        $this->registerClassAlias(DefaultSource::class, $sourceAlias);

        try {
            new MappingDefinition($sourceAlias, DefaultTarget::class);
            self::fail('Expected an aliased exact-pair source to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'Mapping source class "' . $sourceAlias . '" must use its canonical class name "' . DefaultSource::class . '".',
                $exception->getMessage(),
            );
        }

        $mapper = $this->mapper(true, new MappingDefinition(DefaultSource::class, DefaultTarget::class));
        self::assertSame('default', $mapper->map(new DefaultSource(1), DefaultTarget::class)->label);
    }

    public function testItUsesExplicitRulesInsteadOfSameNameConventions(): void
    {
        $profileTarget = $this->mapper(true, new MappingDefinition(
            RulePrecedenceSource::class,
            ProfileTarget::class,
            [
                'id'    => MapRule::from('uuid'),
                'email' => MapRule::fromGetter('getPrimaryEmail'),
            ],
            ['id'],
        ))->map(new RulePrecedenceSource(3, 17), ProfileTarget::class);

        self::assertSame(17, $profileTarget->id);
        self::assertSame('profile@example.test', $profileTarget->email);
    }

    public function testItDoesNotReuseAnInMemoryMapperForADifferentProfileOfTheSamePair(): void
    {
        $valueTransformerRegistry   = new ValueTransformerRegistry();
        $mapperCache                = new MapperCache(
            new MappingMetadataFactory($valueTransformerRegistry),
            new PhpMapperGenerator(),
            $this->cacheDirectory,
            $valueTransformerRegistry,
            true,
        );
        $firstProfile = new MappingDefinition(
            RulePrecedenceSource::class,
            ProfileTarget::class,
            [
                'id'    => MapRule::from('uuid'),
                'email' => MapRule::fromGetter('getPrimaryEmail'),
            ],
            ['id'],
        );
        $secondProfile = new MappingDefinition(
            RulePrecedenceSource::class,
            ProfileTarget::class,
            [
                'id'    => MapRule::from('id'),
                'email' => MapRule::fromGetter('getEmail'),
            ],
            ['uuid'],
        );

        $rulePrecedenceSource             = new RulePrecedenceSource(3, 17);
        $firstProfileTarget               = $mapperCache->get($firstProfile)->map($rulePrecedenceSource);
        $secondProfileTarget              = $mapperCache->get($secondProfile)->map($rulePrecedenceSource);

        self::assertInstanceOf(ProfileTarget::class, $firstProfileTarget);
        self::assertInstanceOf(ProfileTarget::class, $secondProfileTarget);
        self::assertSame(17, $firstProfileTarget->id);
        self::assertSame('profile@example.test', $firstProfileTarget->email);
        self::assertSame(3, $secondProfileTarget->id);
        self::assertSame('conventional@example.test', $secondProfileTarget->email);
        self::assertCount(2, glob($this->cacheDirectory . '/Mapper_*.php') ?: []);
    }

    public function testItSanitizesGeneratedMapperExecutionFailures(): void
    {
        $mapper = $this->mapper(true, new MappingDefinition(ThrowingGetterSource::class, NameTarget::class));

        try {
            $mapper->map(new ThrowingGetterSource(), NameTarget::class);
            self::fail('Expected generated mapper execution to fail.');
        } catch (MappingExecutionFailed $exception) {
            self::assertStringContainsString(ThrowingGetterSource::class . '->' . NameTarget::class, $exception->getMessage());
            self::assertStringNotContainsString('sensitive value', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function testItSanitizesThrownTransformerErrors(): void
    {
        $mappingDefinition = new MappingDefinition(
            ExplicitMethodSource::class,
            ExplicitMethodTarget::class,
            [
                'id'        => MapRule::fromMethod('getIdentifier')->through(ThrowingTransformer::class),
                'createdAt' => MapRule::fromMethod('createdAt')->through(DateTimeToAtomTransformer::class),
                'slug'      => MapRule::fromMethod('slug'),
            ],
        );
        $objectMapper = $this->mapperWithTransformers(true, new ValueTransformerRegistry([
            new ThrowingTransformer(),
            new DateTimeToAtomTransformer(),
        ]), $mappingDefinition);

        try {
            $objectMapper->map(
                new ExplicitMethodSource(new Uuid('sensitive uuid'), new DateTimeImmutable('2026-08-28T10:00:00+00:00')),
                ExplicitMethodTarget::class,
            );
            self::fail('Expected transformer execution to fail.');
        } catch (MappingExecutionFailed $exception) {
            self::assertStringContainsString(ExplicitMethodSource::class . '->' . ExplicitMethodTarget::class, $exception->getMessage());
            self::assertStringNotContainsString('sensitive uuid', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function testItRejectsForeignDefinitionImplementationsAtExecution(): void
    {
        $mapper = $this->mapper(true, new ForeignMappingDefinition());

        try {
            $mapper->map(new DefaultSource(1), DefaultTarget::class);
            self::fail('Expected foreign definition execution to be rejected.');
        } catch (MappingExecutionFailed $exception) {
            self::assertSame(
                'Mapping ' . DefaultSource::class . '->' . DefaultTarget::class . ' has an unsupported definition type ' . ForeignMappingDefinition::class . '.',
                $exception->getMessage(),
            );
        }
    }

    public function testItRejectsAStatefulRegistryReplacingACompiledDependency(): void
    {
        $customMappingDefinition      = new CustomMappingDefinition(Release::class, ReleaseDto::class, new StatefulReleaseMapper('initial-'));
        $statefulDependencyRegistry   = new StatefulDependencyRegistry(
            new MappingRegistry([
                $customMappingDefinition,
                new MappingDefinition(ReleaseCollectionSource::class, ReleaseCollectionDto::class, [
                    'releases' => MapRule::from('releases')->collection(Release::class, ReleaseDto::class),
                ]),
            ]),
            Release::class,
            ReleaseDto::class,
            new CustomMappingDefinition(Release::class, ReleaseDto::class, new StatefulReleaseMapper('replacement-')),
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
            ),
        );

        try {
            $objectMapper->map(new ReleaseCollectionSource([new Release('version')]), ReleaseCollectionDto::class);
            self::fail('Expected a replaced runtime dependency to fail.');
        } catch (MappingCompilationFailed $exception) {
            self::assertSame('Nested mapping dependency does not match its compiled definition.', $exception->getMessage());
        }
    }

    public function testItRejectsAStatefulRegistryReplacingOnlyAChildSourceMatchMode(): void
    {
        $child = new MappingDefinition(
            CycleProxyEntity::class,
            CycleProxyEntityDto::class,
            sourceMatch: SourceMatchMode::CycleProxy,
        );
        $parent = new MappingDefinition(CycleProxyCollectionHolder::class, CycleProxyCollectionHolderDto::class, [
            'children' => MapRule::from('children')->collection(CycleProxyEntity::class, CycleProxyEntityDto::class),
        ]);
        $statefulDependencyRegistry = new StatefulDependencyRegistry(
            new MappingRegistry([$child, $parent]),
            CycleProxyEntity::class,
            CycleProxyEntityDto::class,
            new MappingDefinition(CycleProxyEntity::class, CycleProxyEntityDto::class),
        );
        $valueTransformerRegistry       = new ValueTransformerRegistry();
        $objectMapper                   = new ObjectMapper(
            $statefulDependencyRegistry,
            new MapperCache(
                new MappingMetadataFactory($valueTransformerRegistry, mappingRegistry: $statefulDependencyRegistry),
                new PhpMapperGenerator(),
                $this->cacheDirectory,
                $valueTransformerRegistry,
                true,
                $statefulDependencyRegistry,
            ),
        );

        $this->expectException(MappingCompilationFailed::class);
        $this->expectExceptionMessage('Nested mapping dependency does not match its compiled definition.');
        $objectMapper->map(new CycleProxyCollectionHolder([new DirectCycleProxy(1)]), CycleProxyCollectionHolderDto::class);
    }

    public function testItRejectsAStatefulRegistryReturningTheWrongPrecomputedPair(): void
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
            new MappingDefinition(AccessToken::class, ApiAccessTokenDto::class),
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
            ),
        );

        try {
            $objectMapper->map(new ReleaseCollectionSource([new Release('version')]), ReleaseCollectionDto::class);
            self::fail('Expected a wrong runtime dependency pair to fail.');
        } catch (MappingCompilationFailed $exception) {
            self::assertSame('Nested mapping dependency does not match its compiled definition.', $exception->getMessage());
        }
    }

    public function testItRejectsAStatefulRegistryReplacingATransitiveCompiledDependency(): void
    {
        $customMappingDefinition = new CustomMappingDefinition(
            ThreeLevelLeafSource::class,
            ThreeLevelLeafDto::class,
            new StatefulLeafMapper('initial-'),
        );
        $statefulDependencyRegistry = new StatefulDependencyRegistry(
            new MappingRegistry([
                $customMappingDefinition,
                new MappingDefinition(ThreeLevelMiddleSource::class, ThreeLevelMiddleDto::class, [
                    'child' => MapRule::from('child')->nested(ThreeLevelLeafDto::class),
                ]),
                new MappingDefinition(ThreeLevelRootSource::class, ThreeLevelRootDto::class, [
                    'child' => MapRule::from('child')->nested(ThreeLevelMiddleDto::class),
                ]),
            ]),
            ThreeLevelLeafSource::class,
            ThreeLevelLeafDto::class,
            new CustomMappingDefinition(
                ThreeLevelLeafSource::class,
                ThreeLevelLeafDto::class,
                new StatefulLeafMapper('replacement-'),
            ),
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
            ),
        );

        try {
            $objectMapper->map(
                new ThreeLevelRootSource(new ThreeLevelMiddleSource(new ThreeLevelLeafSource('leaf'))),
                ThreeLevelRootDto::class,
            );
            self::fail('Expected a replaced transitive runtime dependency to fail.');
        } catch (MappingCompilationFailed $exception) {
            self::assertSame('Nested mapping dependency does not match its compiled definition.', $exception->getMessage());
        }
    }

    /**
     * @param class-string $class
     *
     * @phpstan-assert class-string $alias
     */
    private function registerClassAlias(string $class, string $alias): void
    {
        class_alias($class, $alias);

        if (! class_exists($alias)) {
            self::fail('Could not register integration test class alias.');
        }
    }
}
