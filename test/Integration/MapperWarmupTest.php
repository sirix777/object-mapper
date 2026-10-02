<?php

declare(strict_types=1);

namespace Sirix\ObjectMapperTest\Integration;

use Fiber;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Sirix\ObjectMapper\Contract\CustomObjectMapperInterface;
use Sirix\ObjectMapper\Contract\ObjectMapperInterface;
use Sirix\ObjectMapper\Contract\WarmableObjectMapperInterface;
use Sirix\ObjectMapper\Definition\CustomMappingDefinition;
use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Definition\MapRule;
use Sirix\ObjectMapper\Definition\ProviderCustomMappingDefinition;
use Sirix\ObjectMapper\Exception\MappingCompilationFailed;
use Sirix\ObjectMapper\Exception\MappingExecutionFailed;
use Sirix\ObjectMapper\Generator\MapperCache;
use Sirix\ObjectMapper\Generator\PhpMapperGenerator;
use Sirix\ObjectMapper\Metadata\MappingMetadataFactory;
use Sirix\ObjectMapper\Runtime\MappingRegistry;
use Sirix\ObjectMapper\Runtime\ObjectMapper;
use Sirix\ObjectMapper\Runtime\ValueTransformerRegistry;
use Sirix\ObjectMapperTest\Support\AccessToken;
use Sirix\ObjectMapperTest\Support\ApiAccessTokenDto;
use Sirix\ObjectMapperTest\Support\ConventionalSource;
use Sirix\ObjectMapperTest\Support\ConventionalTarget;
use Sirix\ObjectMapperTest\Support\CustomChildDto;
use Sirix\ObjectMapperTest\Support\CustomChildHolderDto;
use Sirix\ObjectMapperTest\Support\CustomChildHolderSource;
use Sirix\ObjectMapperTest\Support\CustomChildSource;
use Sirix\ObjectMapperTest\Support\DefaultSource;
use Sirix\ObjectMapperTest\Support\DefaultTarget;
use Sirix\ObjectMapperTest\Support\IdTarget;
use Sirix\ObjectMapperTest\Support\IndirectCycleDtoA;
use Sirix\ObjectMapperTest\Support\IndirectCycleDtoB;
use Sirix\ObjectMapperTest\Support\IndirectCycleDtoC;
use Sirix\ObjectMapperTest\Support\IndirectCycleSourceA;
use Sirix\ObjectMapperTest\Support\IndirectCycleSourceB;
use Sirix\ObjectMapperTest\Support\IndirectCycleSourceC;
use Sirix\ObjectMapperTest\Support\MissingTarget;
use Sirix\ObjectMapperTest\Support\NullableTokenHolderDto;
use Sirix\ObjectMapperTest\Support\NullableTokenHolderSource;
use Sirix\ObjectMapperTest\Support\PrivateSource;
use Sirix\ObjectMapperTest\Support\RecordingCustomChildMapper;
use Sirix\ObjectMapperTest\Support\RecordingCustomMapperProvider;
use Sirix\ObjectMapperTest\Support\RecordingProviderCustomMapper;
use Sirix\ObjectMapperTest\Support\Release;
use Sirix\ObjectMapperTest\Support\ReleaseCollectionDto;
use Sirix\ObjectMapperTest\Support\ReleaseCollectionSource;
use Sirix\ObjectMapperTest\Support\ReleaseDto;
use Sirix\ObjectMapperTest\Support\SelfCycleDto;
use Sirix\ObjectMapperTest\Support\SelfCycleSource;
use Sirix\ObjectMapperTest\Support\ThreeLevelLeafDto;
use Sirix\ObjectMapperTest\Support\ThreeLevelLeafSource;
use Sirix\ObjectMapperTest\Support\ThreeLevelMiddleDto;
use Sirix\ObjectMapperTest\Support\ThreeLevelMiddleSource;
use Sirix\ObjectMapperTest\Support\ThreeLevelRootDto;
use Sirix\ObjectMapperTest\Support\ThreeLevelRootSource;
use Sirix\ObjectMapperTest\Support\ThrowingCustomMapperProvider;
use Sirix\ObjectMapperTest\Support\TokenHolderDto;
use Sirix\ObjectMapperTest\Support\TokenHolderSource;
use Sirix\ObjectMapperTest\Support\TwoCycleDtoA;
use Sirix\ObjectMapperTest\Support\TwoCycleDtoB;
use Sirix\ObjectMapperTest\Support\TwoCycleSourceA;
use Sirix\ObjectMapperTest\Support\TwoCycleSourceB;

use function array_map;
use function glob;
use function substr_count;

#[CoversClass(ObjectMapper::class)]
final class MapperWarmupTest extends ObjectMapperIntegrationTestCase
{
    public function testItImplementsSeparateMappingAndWarmupContracts(): void
    {
        $mapper = $this->mapper(true);

        self::assertInstanceOf(ObjectMapperInterface::class, $mapper);
        self::assertInstanceOf(WarmableObjectMapperInterface::class, $mapper);
    }

    public function testWarmupIsIdempotent(): void
    {
        $mapper = $this->mapper(
            false,
            new MappingDefinition(ConventionalSource::class, ConventionalTarget::class),
            new MappingDefinition(DefaultSource::class, DefaultTarget::class),
        );

        $expected = [
            ConventionalSource::class . '->' . ConventionalTarget::class,
            DefaultSource::class . '->' . DefaultTarget::class,
        ];

        self::assertSame($expected, $mapper->warmup());
        self::assertSame($expected, $mapper->warmup());
        self::assertCount(2, glob($this->cacheDirectory . '/Mapper_*.php') ?: []);
    }

    public function testWarmupReportsEveryInvalidMapping(): void
    {
        $mapper = $this->mapper(
            false,
            new MappingDefinition(DefaultSource::class, MissingTarget::class),
            new MappingDefinition(PrivateSource::class, IdTarget::class),
        );

        try {
            $mapper->warmup();
            self::fail('Expected warmup to fail.');
        } catch (MappingCompilationFailed $exception) {
            self::assertStringContainsString(DefaultSource::class . '->' . MissingTarget::class, $exception->getMessage());
            self::assertStringContainsString(PrivateSource::class . '->' . IdTarget::class, $exception->getMessage());
        }
    }

    public function testWarmupSkipsCustomMappings(): void
    {
        $mapper = $this->mapper(
            false,
            new MappingDefinition(DefaultSource::class, DefaultTarget::class),
            new CustomMappingDefinition(
                ConventionalSource::class,
                ConventionalTarget::class,
                new class implements CustomObjectMapperInterface {
                    public function map(object $source): object
                    {
                        throw new RuntimeException('Custom mappers must not execute during warmup.');
                    }
                },
            ),
        );

        self::assertSame([DefaultSource::class . '->' . DefaultTarget::class], $mapper->warmup());
        self::assertCount(1, glob($this->cacheDirectory . '/Mapper_*.php') ?: []);
    }

    public function testWarmupDoesNotResolveProviderBackedCustomMappings(): void
    {
        $throwingCustomMapperProvider = new ThrowingCustomMapperProvider();
        $objectMapper                 = $this->mapperWithProvider(
            false,
            $throwingCustomMapperProvider,
            new ProviderCustomMappingDefinition(CustomChildSource::class, CustomChildDto::class, 'child-mapper'),
            new ProviderCustomMappingDefinition(Release::class, ReleaseDto::class, 'child-mapper'),
            new MappingDefinition(CustomChildHolderSource::class, CustomChildHolderDto::class, [
                'child' => MapRule::from('child')->nested(CustomChildDto::class),
            ]),
            new MappingDefinition(ReleaseCollectionSource::class, ReleaseCollectionDto::class, [
                'releases' => MapRule::from('releases')->collection(Release::class, ReleaseDto::class),
            ]),
        );

        self::assertSame([
            CustomChildHolderSource::class . '->' . CustomChildHolderDto::class,
            ReleaseCollectionSource::class . '->' . ReleaseCollectionDto::class,
        ], $objectMapper->warmup());
        self::assertSame(0, $throwingCustomMapperProvider->lookups);

        $recordingProviderCustomMapper   = new RecordingProviderCustomMapper();
        $recordingCustomMapperProvider   = new RecordingCustomMapperProvider([
            'child-mapper' => $recordingProviderCustomMapper,
        ]);
        $freshMapper = $this->mapperWithProvider(
            false,
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

        self::assertSame('runtime', $freshMapper->map(
            new CustomChildHolderSource(new CustomChildSource('runtime')),
            CustomChildHolderDto::class,
        )->child->label);
        self::assertSame(['runtime'], array_map(
            static fn (ReleaseDto $releaseDto): string => $releaseDto->version,
            $freshMapper->map(new ReleaseCollectionSource([new Release('runtime')]), ReleaseCollectionDto::class)->releases,
        ));
        self::assertSame(2, $recordingCustomMapperProvider->lookups);
        self::assertSame(2, $recordingProviderCustomMapper->invocations);
    }

    public function testWarmupHandlesMultiLevelAndCustomChildrenWithoutExecutingCustomCode(): void
    {
        $recordingCustomChildMapper = new RecordingCustomChildMapper();
        $mapper                     = $this->mapper(
            false,
            new MappingDefinition(ThreeLevelLeafSource::class, ThreeLevelLeafDto::class),
            new MappingDefinition(ThreeLevelMiddleSource::class, ThreeLevelMiddleDto::class, [
                'child' => MapRule::from('child')->nested(ThreeLevelLeafDto::class),
            ]),
            new MappingDefinition(ThreeLevelRootSource::class, ThreeLevelRootDto::class, [
                'child' => MapRule::from('child')->nested(ThreeLevelMiddleDto::class),
            ]),
            new CustomMappingDefinition(CustomChildSource::class, CustomChildDto::class, $recordingCustomChildMapper),
            new MappingDefinition(CustomChildHolderSource::class, CustomChildHolderDto::class, [
                'child' => MapRule::from('child')->nested(CustomChildDto::class),
            ]),
        );

        $mapper->warmup();
        self::assertSame(0, $recordingCustomChildMapper->invocations);
        self::assertSame('leaf', $mapper->map(
            new ThreeLevelRootSource(new ThreeLevelMiddleSource(new ThreeLevelLeafSource('leaf'))),
            ThreeLevelRootDto::class,
        )->child->child->label);
        self::assertSame('custom', $mapper->map(new CustomChildHolderSource(new CustomChildSource('custom')), CustomChildHolderDto::class)->child->label);
        self::assertSame(1, $recordingCustomChildMapper->invocations);
    }

    public function testWarmupDeduplicatesStructuredCycles(): void
    {
        $self = new MappingDefinition(SelfCycleSource::class, SelfCycleDto::class, [
            'child' => MapRule::from('child')->nested(SelfCycleDto::class),
        ]);
        $twoA = new MappingDefinition(TwoCycleSourceA::class, TwoCycleDtoA::class, [
            'child' => MapRule::from('child')->nested(TwoCycleDtoB::class),
        ]);
        $twoB = new MappingDefinition(TwoCycleSourceB::class, TwoCycleDtoB::class, [
            'child' => MapRule::from('child')->nested(TwoCycleDtoA::class),
        ]);
        $threeA = new MappingDefinition(IndirectCycleSourceA::class, IndirectCycleDtoA::class, [
            'child' => MapRule::from('child')->nested(IndirectCycleDtoB::class),
        ]);
        $threeB = new MappingDefinition(IndirectCycleSourceB::class, IndirectCycleDtoB::class, [
            'child' => MapRule::from('child')->nested(IndirectCycleDtoC::class),
        ]);
        $threeC = new MappingDefinition(IndirectCycleSourceC::class, IndirectCycleDtoC::class, [
            'child' => MapRule::from('child')->nested(IndirectCycleDtoA::class),
        ]);

        $mapper = $this->mapper(false, $self, $twoA, $twoB, $threeA, $threeB, $threeC);

        try {
            $mapper->warmup();
            self::fail('Expected warmup to report dependency cycles.');
        } catch (MappingCompilationFailed $exception) {
            self::assertSame(3, substr_count($exception->getMessage(), 'dependency cycle detected'));
        }
    }

    public function testWarmupDoesNotPublishAParentWhenItsChildFails(): void
    {
        $mapper = $this->mapper(
            false,
            new MappingDefinition(AccessToken::class, MissingTarget::class),
            new MappingDefinition(TokenHolderSource::class, InvalidChildHolderDto::class, [
                'token' => MapRule::from('token')->nested(MissingTarget::class),
            ]),
            new MappingDefinition(SecondInvalidChildHolderSource::class, SecondInvalidChildHolderDto::class, [
                'token' => MapRule::from('token')->nested(MissingTarget::class),
            ]),
        );

        try {
            $mapper->warmup();
            self::fail('Expected invalid child warmup to fail.');
        } catch (MappingCompilationFailed $exception) {
            self::assertStringContainsString(AccessToken::class . '->' . MissingTarget::class, $exception->getMessage());
            self::assertSame(1, substr_count($exception->getMessage(), AccessToken::class . '->' . MissingTarget::class));
        }

        self::assertSame([], glob($this->cacheDirectory . '/Mapper_*.php') ?: []);
    }

    public function testWarmupUsesItsCompiledDependencySnapshotInsteadOfAMutableRegistry(): void
    {
        foreach ([
            'replacement' => new MappingDefinition(Release::class, ReleaseDto::class),
            'wrong pair'  => new MappingDefinition(AccessToken::class, ApiAccessTokenDto::class),
            'throw'       => new RuntimeException('warmup-registry-secret'),
        ] as $name => $replacement) {
            $child  = new MappingDefinition(Release::class, ReleaseDto::class);
            $parent = new MappingDefinition(ReleaseCollectionSource::class, ReleaseCollectionDto::class, [
                'releases' => MapRule::from('releases')->collection(Release::class, ReleaseDto::class),
            ]);
            $mappingRegistry          = new WarmupSnapshotRegistry(
                new MappingRegistry([$child, $parent]),
                Release::class,
                ReleaseDto::class,
                $replacement,
            );
            $valueTransformerRegistry = new ValueTransformerRegistry();
            $mapper                   = new ObjectMapper(
                $mappingRegistry,
                new MapperCache(
                    new MappingMetadataFactory($valueTransformerRegistry, mappingRegistry: $mappingRegistry),
                    new PhpMapperGenerator(),
                    $this->cacheDirectory,
                    $valueTransformerRegistry,
                    false,
                    $mappingRegistry,
                ),
            );

            self::assertSame([
                Release::class . '->' . ReleaseDto::class,
                ReleaseCollectionSource::class . '->' . ReleaseCollectionDto::class,
            ], $mapper->warmup(), $name);
            self::assertSame(1, $mappingRegistry->dependencyReads(), $name);
        }
    }

    public function testWarmupRetainsEachRootDependencySnapshot(): void
    {
        $mappingRegistry          = new MappingRegistry([
            new MappingDefinition(AccessToken::class, ApiAccessTokenDto::class),
            new MappingDefinition(TokenHolderSource::class, TokenHolderDto::class, [
                'token' => MapRule::from('token')->nested(ApiAccessTokenDto::class),
            ]),
            new MappingDefinition(NullableTokenHolderSource::class, NullableTokenHolderDto::class, [
                'token' => MapRule::from('token')->nested(ApiAccessTokenDto::class),
            ]),
        ]);
        $valueTransformerRegistry = new ValueTransformerRegistry();
        $objectMapper             = new ObjectMapper(
            $mappingRegistry,
            new MapperCache(
                new MappingMetadataFactory($valueTransformerRegistry, mappingRegistry: $mappingRegistry),
                new PhpMapperGenerator(),
                $this->cacheDirectory,
                $valueTransformerRegistry,
                false,
                $mappingRegistry,
            ),
        );

        self::assertSame([
            AccessToken::class . '->' . ApiAccessTokenDto::class,
            NullableTokenHolderSource::class . '->' . NullableTokenHolderDto::class,
            TokenHolderSource::class . '->' . TokenHolderDto::class,
        ], $objectMapper->warmup());
    }

    public function testWarmupRejectsConflictingMultiRootDependencySnapshots(): void
    {
        $mappingDefinition              = new MappingDefinition(AccessToken::class, ApiAccessTokenDto::class);
        $sequentialDependencyRegistry   = new SequentialDependencyRegistry(
            new MappingRegistry([
                $mappingDefinition,
                new MappingDefinition(DefaultSource::class, MissingTarget::class),
                new MappingDefinition(TokenHolderSource::class, TokenHolderDto::class, [
                    'token' => MapRule::from('token')->nested(ApiAccessTokenDto::class),
                ]),
                new MappingDefinition(NullableTokenHolderSource::class, NullableTokenHolderDto::class, [
                    'token' => MapRule::from('token')->nested(ApiAccessTokenDto::class),
                ]),
            ]),
            AccessToken::class,
            ApiAccessTokenDto::class,
            new MappingDefinition(AccessToken::class, ApiAccessTokenDto::class),
        );
        $valueTransformerRegistry = new ValueTransformerRegistry();
        $objectMapper             = new ObjectMapper(
            $sequentialDependencyRegistry,
            new MapperCache(
                new MappingMetadataFactory($valueTransformerRegistry, mappingRegistry: $sequentialDependencyRegistry),
                new PhpMapperGenerator(),
                $this->cacheDirectory,
                $valueTransformerRegistry,
                false,
                $sequentialDependencyRegistry,
            ),
        );

        try {
            $objectMapper->warmup();
            self::fail('Expected conflicting dependency snapshots to fail.');
        } catch (MappingCompilationFailed $exception) {
            self::assertStringContainsString(AccessToken::class . '->' . ApiAccessTokenDto::class, $exception->getMessage());
            self::assertStringContainsString('Conflicting compiled dependency snapshots.', $exception->getMessage());
            self::assertStringContainsString(DefaultSource::class . '->' . MissingTarget::class, $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }

        self::assertSame([], glob($this->cacheDirectory . '/Mapper_*.php') ?: []);
    }

    public function testWarmupSanitizesRegistryEnumerationFailures(): void
    {
        $valueTransformerRegistry   = new ValueTransformerRegistry();
        $throwingAllMappingRegistry = new ThrowingAllMappingRegistry();
        $objectMapper               = new ObjectMapper(
            $throwingAllMappingRegistry,
            new MapperCache(
                new MappingMetadataFactory($valueTransformerRegistry, mappingRegistry: $throwingAllMappingRegistry),
                new PhpMapperGenerator(),
                $this->cacheDirectory,
                $valueTransformerRegistry,
                false,
                $throwingAllMappingRegistry,
            ),
        );

        try {
            $objectMapper->warmup();
            self::fail('Expected registry enumeration to fail.');
        } catch (MappingCompilationFailed $exception) {
            self::assertSame('Could not enumerate registered mappings.', $exception->getMessage());
            self::assertStringNotContainsString('all-registry-secret', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function testWarmupSanitizesHostileRegistryDefinitionsBeforeInterrogatingThem(): void
    {
        $mappingDefinition       = new MappingDefinition(TokenHolderSource::class, TokenHolderDto::class, [
            'token' => MapRule::from('token')->nested(ApiAccessTokenDto::class),
        ]);
        $hostileDependencyRegistry = new HostileDependencyRegistry(
            new MappingRegistry([$mappingDefinition]),
            AccessToken::class,
            ApiAccessTokenDto::class,
        );
        $valueTransformerRegistry = new ValueTransformerRegistry();
        $objectMapper             = new ObjectMapper(
            $hostileDependencyRegistry,
            new MapperCache(
                new MappingMetadataFactory($valueTransformerRegistry, mappingRegistry: $hostileDependencyRegistry),
                new PhpMapperGenerator(),
                $this->cacheDirectory,
                $valueTransformerRegistry,
                false,
                $hostileDependencyRegistry,
            ),
        );

        try {
            $objectMapper->warmup();
            self::fail('Expected the hostile registry definition to be rejected.');
        } catch (MappingCompilationFailed $exception) {
            self::assertStringContainsString(TokenHolderSource::class . '->' . TokenHolderDto::class, $exception->getMessage());
            self::assertStringNotContainsString('hostile-source-secret', $exception->getMessage());
            self::assertStringNotContainsString('hostile-key-secret', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function testPreparedCacheIsolatesInterleavedFiberScopesAfterWarmup(): void
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
            reusePreparedMappings: true,
        );
        $objectMapper = new ObjectMapper($mappingRegistry, $mapperCache);
        $objectMapper->warmup();

        $first = new Fiber(static function() use ($objectMapper, $mapperCache): string {
            try {
                $objectMapper->map(new FiberCollectionFailureSource($mapperCache, true, 1), FiberCollectionFailureDto::class);
            } catch (MappingExecutionFailed $exception) {
                return $exception->getMessage();
            }

            return 'mapping unexpectedly succeeded';
        });
        $second = new Fiber(static function() use ($objectMapper, $mapperCache): string {
            try {
                $objectMapper->map(new FiberCollectionFailureSource($mapperCache, true, 2), FiberCollectionFailureDto::class);
            } catch (MappingExecutionFailed $exception) {
                return $exception->getMessage();
            }

            return 'mapping unexpectedly succeeded';
        });

        $first->start();
        $second->start();
        $first->resume();
        $second->resume();

        self::assertStringContainsString('integer key 1', $first->getReturn());
        self::assertStringContainsString('integer key 2', $second->getReturn());
    }
}
