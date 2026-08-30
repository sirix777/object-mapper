<?php

declare(strict_types=1);

namespace Sirix\ObjectMapperTest\Integration;

use DateTimeImmutable;
use Fiber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Sirix\ObjectMapper\Contract\CustomObjectMapperInterface;
use Sirix\ObjectMapper\Contract\CustomObjectMapperProviderInterface;
use Sirix\ObjectMapper\Contract\MappingDefinitionInterface;
use Sirix\ObjectMapper\Contract\MappingRegistryInterface;
use Sirix\ObjectMapper\Definition\CustomMappingDefinition;
use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Definition\MapRule;
use Sirix\ObjectMapper\Definition\ProviderCustomMappingDefinition;
use Sirix\ObjectMapper\Exception\MappingCompilationFailed;
use Sirix\ObjectMapper\Exception\MappingExecutionFailed;
use Sirix\ObjectMapper\Exception\MappingNotRegistered;
use Sirix\ObjectMapper\Generator\MapperCache;
use Sirix\ObjectMapper\Generator\PhpMapperGenerator;
use Sirix\ObjectMapper\Metadata\MappingMetadata;
use Sirix\ObjectMapper\Metadata\MappingMetadataFactory;
use Sirix\ObjectMapper\Metadata\NestedMappingMetadata;
use Sirix\ObjectMapper\Runtime\CustomMappingExecutor;
use Sirix\ObjectMapper\Runtime\GeneratedMappingExecutionFailed;
use Sirix\ObjectMapper\Runtime\MappingRegistry;
use Sirix\ObjectMapper\Runtime\ObjectMapper;
use Sirix\ObjectMapper\Runtime\ValueTransformerRegistry;
use Sirix\ObjectMapperTest\Support\AccessToken;
use Sirix\ObjectMapperTest\Support\ApiAccessTokenDto;
use Sirix\ObjectMapperTest\Support\ConstantSource;
use Sirix\ObjectMapperTest\Support\ConstantTarget;
use Sirix\ObjectMapperTest\Support\ConventionalSource;
use Sirix\ObjectMapperTest\Support\ConventionalTarget;
use Sirix\ObjectMapperTest\Support\CustomChildDto;
use Sirix\ObjectMapperTest\Support\CustomChildHolderDto;
use Sirix\ObjectMapperTest\Support\CustomChildHolderSource;
use Sirix\ObjectMapperTest\Support\CustomChildSource;
use Sirix\ObjectMapperTest\Support\DateTimeToAtomTransformer;
use Sirix\ObjectMapperTest\Support\DefaultSource;
use Sirix\ObjectMapperTest\Support\DefaultTarget;
use Sirix\ObjectMapperTest\Support\ExplicitMethodSource;
use Sirix\ObjectMapperTest\Support\ExplicitMethodTarget;
use Sirix\ObjectMapperTest\Support\IdTarget;
use Sirix\ObjectMapperTest\Support\IndirectCycleDtoA;
use Sirix\ObjectMapperTest\Support\IndirectCycleDtoB;
use Sirix\ObjectMapperTest\Support\IndirectCycleDtoC;
use Sirix\ObjectMapperTest\Support\IndirectCycleSourceA;
use Sirix\ObjectMapperTest\Support\IndirectCycleSourceB;
use Sirix\ObjectMapperTest\Support\IndirectCycleSourceC;
use Sirix\ObjectMapperTest\Support\InvalidCustomMapperProvider;
use Sirix\ObjectMapperTest\Support\MissingTarget;
use Sirix\ObjectMapperTest\Support\MixedConstantTarget;
use Sirix\ObjectMapperTest\Support\NameTarget;
use Sirix\ObjectMapperTest\Support\NullableReleaseCollectionDto;
use Sirix\ObjectMapperTest\Support\NullableReleaseCollectionSource;
use Sirix\ObjectMapperTest\Support\NullableTokenHolderDto;
use Sirix\ObjectMapperTest\Support\NullableTokenHolderSource;
use Sirix\ObjectMapperTest\Support\PrivateSource;
use Sirix\ObjectMapperTest\Support\ProfileSource;
use Sirix\ObjectMapperTest\Support\ProfileTarget;
use Sirix\ObjectMapperTest\Support\ProviderMapperLabelService;
use Sirix\ObjectMapperTest\Support\RecordingCustomChildMapper;
use Sirix\ObjectMapperTest\Support\RecordingCustomMapperProvider;
use Sirix\ObjectMapperTest\Support\RecordingProviderCustomMapper;
use Sirix\ObjectMapperTest\Support\Release;
use Sirix\ObjectMapperTest\Support\ReleaseCollectionDto;
use Sirix\ObjectMapperTest\Support\ReleaseCollectionSource;
use Sirix\ObjectMapperTest\Support\ReleaseDto;
use Sirix\ObjectMapperTest\Support\RulePrecedenceSource;
use Sirix\ObjectMapperTest\Support\SameNamedConstantSource;
use Sirix\ObjectMapperTest\Support\ScalarConstantsTarget;
use Sirix\ObjectMapperTest\Support\SelfCycleDto;
use Sirix\ObjectMapperTest\Support\SelfCycleSource;
use Sirix\ObjectMapperTest\Support\ServiceDependentProviderCustomMapper;
use Sirix\ObjectMapperTest\Support\ThreeLevelLeafDto;
use Sirix\ObjectMapperTest\Support\ThreeLevelLeafSource;
use Sirix\ObjectMapperTest\Support\ThreeLevelMiddleDto;
use Sirix\ObjectMapperTest\Support\ThreeLevelMiddleSource;
use Sirix\ObjectMapperTest\Support\ThreeLevelRootDto;
use Sirix\ObjectMapperTest\Support\ThreeLevelRootSource;
use Sirix\ObjectMapperTest\Support\ThrowingCustomMapperProvider;
use Sirix\ObjectMapperTest\Support\ThrowingGetterSource;
use Sirix\ObjectMapperTest\Support\ThrowingTransformer;
use Sirix\ObjectMapperTest\Support\TokenHolderDto;
use Sirix\ObjectMapperTest\Support\TokenHolderSource;
use Sirix\ObjectMapperTest\Support\Uuid;
use Sirix\ObjectMapperTest\Support\UuidToStringTransformer;
use stdClass;
use Throwable;

use function array_keys;
use function array_map;
use function bin2hex;
use function chmod;
use function count;
use function file_get_contents;
use function fileperms;
use function glob;
use function hash;
use function implode;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function str_repeat;
use function strlen;
use function substr;
use function substr_count;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(ObjectMapper::class)]
final class ObjectMapperTest extends TestCase
{
    private string $cacheDirectory;

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

    public function testItRejectsProductionCacheMisses(): void
    {
        $mapper = $this->mapper(false, new MappingDefinition(DefaultSource::class, DefaultTarget::class));

        $this->expectException(MappingCompilationFailed::class);
        $mapper->map(new DefaultSource(1), DefaultTarget::class);
    }

    public function testItPreservesTargetDefaultsAfterOnDemandGeneration(): void
    {
        $defaultTarget = $this->mapper(true, new MappingDefinition(DefaultSource::class, DefaultTarget::class))
            ->map(new DefaultSource(1), DefaultTarget::class)
        ;

        self::assertSame('default', $defaultTarget->label);
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

    public function testWarmupIsIdempotent(): void
    {
        $mapper = $this->mapper(
            false,
            new MappingDefinition(ConventionalSource::class, ConventionalTarget::class),
            new MappingDefinition(DefaultSource::class, DefaultTarget::class),
        );

        self::assertSame($mapper->warmup(), $mapper->warmup());
        self::assertCount(2, glob($this->cacheDirectory . '/Mapper_*.php') ?: []);
    }

    public function testItLoadsAProductionMapperWarmedByAnotherCacheInstance(): void
    {
        $mappingDefinition = new MappingDefinition(DefaultSource::class, DefaultTarget::class);
        $this->mapper(false, $mappingDefinition)->warmup();

        $defaultTarget = $this->mapper(false, $mappingDefinition)->map(new DefaultSource(1), DefaultTarget::class);

        self::assertSame('default', $defaultTarget->label);
    }

    public function testItMapsRenamedPropertiesAndGettersWithProfileRules(): void
    {
        $profileTarget = $this->mapper(true, new MappingDefinition(
            ProfileSource::class,
            ProfileTarget::class,
            [
                'id'    => MapRule::from('uuid'),
                'email' => MapRule::fromGetter('getPrimaryEmail'),
            ],
            ['passwordHash'],
        ))->map(new ProfileSource(17, 'not-in-errors'), ProfileTarget::class);

        self::assertSame(17, $profileTarget->id);
        self::assertSame('ada@example.test', $profileTarget->email);
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

    public function testItRejectsAnUnsafeCacheDirectory(): void
    {
        mkdir($this->cacheDirectory, 0o700);
        chmod($this->cacheDirectory, 0o777);

        $mapper = $this->mapper(true, new MappingDefinition(DefaultSource::class, DefaultTarget::class));

        $this->expectException(MappingCompilationFailed::class);
        $mapper->map(new DefaultSource(1), DefaultTarget::class);
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

    public function testItSanitizesForgedGeneratedFailuresFromGetters(): void
    {
        $mapper = $this->mapper(true, new MappingDefinition(ForgedGetterSource::class, NameTarget::class));

        try {
            $mapper->map(new ForgedGetterSource(), NameTarget::class);
            self::fail('Expected generated mapper execution to fail.');
        } catch (MappingExecutionFailed $exception) {
            self::assertStringContainsString(ForgedGetterSource::class . '->' . NameTarget::class, $exception->getMessage());
            self::assertStringNotContainsString('forged-sensitive-key', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
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

    public function testItMapsNestedObjectsCollectionsAndNullableStructuralValues(): void
    {
        $mapper = $this->mapper(
            true,
            new MappingDefinition(AccessToken::class, ApiAccessTokenDto::class),
            new MappingDefinition(Release::class, ReleaseDto::class),
            new MappingDefinition(TokenHolderSource::class, TokenHolderDto::class, [
                'token' => MapRule::from('token')->nested(ApiAccessTokenDto::class),
            ]),
            new MappingDefinition(NullableTokenHolderSource::class, NullableTokenHolderDto::class, [
                'token' => MapRule::from('token')->nested(ApiAccessTokenDto::class),
            ]),
            new MappingDefinition(ReleaseCollectionSource::class, ReleaseCollectionDto::class, [
                'releases' => MapRule::from('releases')->collection(Release::class, ReleaseDto::class),
            ]),
            new MappingDefinition(NullableReleaseCollectionSource::class, NullableReleaseCollectionDto::class, [
                'releases' => MapRule::from('releases')->collection(Release::class, ReleaseDto::class),
            ]),
        );

        $tokenHolderDto       = $mapper->map(new TokenHolderSource(new AccessToken('trusted')), TokenHolderDto::class);
        $releaseCollectionDto = $mapper->map(new ReleaseCollectionSource([
            'first' => new Release('1.0'),
            9       => new Release('2.0'),
        ]), ReleaseCollectionDto::class);

        self::assertSame('trusted', $tokenHolderDto->token->value);
        self::assertSame(['1.0', '2.0'], array_map(static fn (ReleaseDto $releaseDto): string => $releaseDto->version, $releaseCollectionDto->releases));
        self::assertSame([0, 1], array_keys($releaseCollectionDto->releases));
        self::assertNull($mapper->map(new NullableTokenHolderSource(null), NullableTokenHolderDto::class)->token);
        self::assertNull($mapper->map(new NullableReleaseCollectionSource(null), NullableReleaseCollectionDto::class)->releases);
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

    public function testItReportsSafeCollectionElementAndCycleFailures(): void
    {
        $mapper = $this->mapper(
            true,
            new MappingDefinition(Release::class, ReleaseDto::class),
            new MappingDefinition(ReleaseCollectionSource::class, ReleaseCollectionDto::class, [
                'releases' => MapRule::from('releases')->collection(Release::class, ReleaseDto::class),
            ]),
        );

        $sensitiveKey = "sensitive-key\n" . str_repeat('very-long-key-', 64);
        $createSource = (static fn (mixed $values): ReleaseCollectionSource => new ReleaseCollectionSource($values));

        try {
            $mapper->map($createSource([
                $sensitiveKey => new AccessToken('secret'),
            ]), ReleaseCollectionDto::class);
            self::fail('Expected an invalid collection element to fail.');
        } catch (MappingExecutionFailed $exception) {
            self::assertStringContainsString('parameter "releases"', $exception->getMessage());
            self::assertStringContainsString('string key sha256:' . substr(hash('sha256', $sensitiveKey), 0, 16), $exception->getMessage());
            self::assertStringContainsString('length ' . strlen($sensitiveKey), $exception->getMessage());
            self::assertStringContainsString(AccessToken::class, $exception->getMessage());
            self::assertStringNotContainsString($sensitiveKey, $exception->getMessage());
            self::assertStringNotContainsString('sensitive-key', $exception->getMessage());
            self::assertStringNotContainsString('secret', $exception->getMessage());
            self::assertStringNotContainsString("\n", $exception->getMessage());
        }

        try {
            $mapper->map($createSource([
                7 => new AccessToken('secret'),
            ]), ReleaseCollectionDto::class);
            self::fail('Expected an invalid collection element to fail.');
        } catch (MappingExecutionFailed $exception) {
            self::assertStringContainsString('integer key 7', $exception->getMessage());
            self::assertStringContainsString(AccessToken::class, $exception->getMessage());
        }

        try {
            $mapper->map($createSource([
                -1 => new AccessToken('secret'),
            ]), ReleaseCollectionDto::class);
            self::fail('Expected an invalid collection element to fail.');
        } catch (MappingExecutionFailed $exception) {
            self::assertStringContainsString('integer key -1', $exception->getMessage());
        }

        $self = new MappingDefinition(SelfCycleSource::class, SelfCycleDto::class, [
            'child' => MapRule::from('child')->nested(SelfCycleDto::class),
        ]);
        $indirectA = new MappingDefinition(IndirectCycleSourceA::class, IndirectCycleDtoA::class, [
            'child' => MapRule::from('child')->nested(IndirectCycleDtoB::class),
        ]);
        $indirectB = new MappingDefinition(IndirectCycleSourceB::class, IndirectCycleDtoB::class, [
            'child' => MapRule::from('child')->nested(IndirectCycleDtoC::class),
        ]);
        $indirectC = new MappingDefinition(IndirectCycleSourceC::class, IndirectCycleDtoC::class, [
            'child' => MapRule::from('child')->nested(IndirectCycleDtoA::class),
        ]);

        foreach ([[$self], [$indirectA, $indirectB, $indirectC]] as $definitions) {
            try {
                $this->mapper(false, ...$definitions)->warmup();
                self::fail('Expected cycle detection during warmup.');
            } catch (MappingCompilationFailed $exception) {
                self::assertStringContainsString('cycle', $exception->getMessage());

                if (3 === count($definitions)) {
                    $cycle = implode(' -> ', [
                        $indirectA->key(),
                        $indirectB->key(),
                        $indirectC->key(),
                        $indirectA->key(),
                    ]);

                    $rotatedCycles = [
                        $cycle,
                        implode(' -> ', [$indirectB->key(), $indirectC->key(), $indirectA->key(), $indirectB->key()]),
                        implode(' -> ', [$indirectC->key(), $indirectA->key(), $indirectB->key(), $indirectC->key()]),
                    ];

                    self::assertSame(
                        1,
                        substr_count($exception->getMessage(), $rotatedCycles[0])
                        + substr_count($exception->getMessage(), $rotatedCycles[1])
                        + substr_count($exception->getMessage(), $rotatedCycles[2]),
                    );
                }
            }
        }
    }

    public function testItRejectsNestedAndCollectionSubclassValues(): void
    {
        $mapper = $this->mapper(
            true,
            new MappingDefinition(ExactChild::class, ExactChildDto::class),
            new MappingDefinition(ExactHolderSource::class, ExactHolderDto::class, [
                'child' => MapRule::from('child')->nested(ExactChildDto::class),
            ]),
            new MappingDefinition(ExactCollectionSource::class, ExactCollectionDto::class, [
                'children' => MapRule::from('children')->collection(ExactChild::class, ExactChildDto::class),
            ]),
        );

        try {
            $mapper->map(new ExactHolderSource(new ExactChildSubclass('nested')), ExactHolderDto::class);
            self::fail('Expected a nested subclass to fail.');
        } catch (MappingExecutionFailed $exception) {
            self::assertStringContainsString(ExactHolderSource::class . '->' . ExactHolderDto::class, $exception->getMessage());
        }

        try {
            $mapper->map(new ExactCollectionSource([new ExactChildSubclass('collection')]), ExactCollectionDto::class);
            self::fail('Expected a collection subclass to fail.');
        } catch (MappingExecutionFailed $exception) {
            self::assertStringContainsString('integer key 0', $exception->getMessage());
        }
    }

    public function testItPreservesValidatedChildCollectionFailureContext(): void
    {
        $mapper = $this->mapper(
            true,
            new MappingDefinition(Release::class, ReleaseDto::class),
            new MappingDefinition(ReleaseCollectionSource::class, ReleaseCollectionDto::class, [
                'releases' => MapRule::from('releases')->collection(Release::class, ReleaseDto::class),
            ]),
            new MappingDefinition(NestedReleaseCollectionSource::class, NestedReleaseCollectionDto::class, [
                'collection' => MapRule::from('collection')->nested(ReleaseCollectionDto::class),
            ]),
        );
        $createCollection = (static fn (mixed $releases): ReleaseCollectionSource => new ReleaseCollectionSource($releases));

        try {
            $mapper->map(
                new NestedReleaseCollectionSource($createCollection([
                    3 => new AccessToken('secret'),
                ])),
                NestedReleaseCollectionDto::class,
            );
            self::fail('Expected nested collection element validation to fail.');
        } catch (MappingExecutionFailed $exception) {
            self::assertStringContainsString(ReleaseCollectionSource::class . '->' . ReleaseCollectionDto::class, $exception->getMessage());
            self::assertStringContainsString('parameter "releases"', $exception->getMessage());
            self::assertStringContainsString('integer key 3', $exception->getMessage());
            self::assertStringContainsString(AccessToken::class, $exception->getMessage());
            self::assertStringNotContainsString('secret', $exception->getMessage());
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

    public function testItReadsNullableNestedGettersOnlyOnceAndUsesDistinctCollectionHelpers(): void
    {
        $nullableGetterHolderSource = new NullableGetterHolderSource(new ExactChild('once'));
        $mapper                     = $this->mapper(
            true,
            new MappingDefinition(ExactChild::class, ExactChildDto::class),
            new MappingDefinition(NullableGetterHolderSource::class, NullableGetterHolderDto::class, [
                'child' => MapRule::fromGetter('getChild')->nested(ExactChildDto::class),
            ]),
            new MappingDefinition(CollectionHelperCollisionSource::class, CollectionHelperCollisionDto::class, [
                'items' => MapRule::from('items')->collection(ExactChild::class, ExactChildDto::class),
                'Items' => MapRule::from('Items')->collection(ExactChild::class, ExactChildDto::class),
            ]),
        );

        $nullableGetterHolderDto = $mapper->map($nullableGetterHolderSource, NullableGetterHolderDto::class);
        self::assertNotNull($nullableGetterHolderDto->child);
        self::assertSame('once', $nullableGetterHolderDto->child->value);
        self::assertSame(1, $nullableGetterHolderSource->readCount());

        $collectionHelperCollisionDto = $mapper->map(new CollectionHelperCollisionSource([new ExactChild('a')], [new ExactChild('b')]), CollectionHelperCollisionDto::class);
        self::assertSame('a', $collectionHelperCollisionDto->items[0]->value);
        self::assertSame('b', $collectionHelperCollisionDto->Items[0]->value);
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

    public function testItSanitizesMaliciousCollectionFailureHelperCalls(): void
    {
        $mapper = $this->mapper(
            true,
            new MappingDefinition(Release::class, ReleaseDto::class),
            new MappingDefinition(MaliciousCollectionFailureSource::class, ReleaseCollectionDto::class, [
                'releases' => MapRule::fromGetter('getReleases')->collection(Release::class, ReleaseDto::class),
            ]),
        );

        try {
            $mapper->map(new MaliciousCollectionFailureSource(), ReleaseCollectionDto::class);
            self::fail('Expected malicious helper invocation to fail.');
        } catch (MappingExecutionFailed $exception) {
            self::assertStringContainsString(MaliciousCollectionFailureSource::class . '->' . ReleaseCollectionDto::class, $exception->getMessage());
            self::assertStringNotContainsString('attacker-secret', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }

        self::assertFalse((new ReflectionClass(GeneratedMappingExecutionFailed::class))->hasProperty('token'));
    }

    public function testItPreventsRebindingValidatedCollectionFailureContexts(): void
    {
        $definitions = [
            new MappingDefinition(Release::class, ReleaseDto::class),
            new MappingDefinition(RebindingCollectionFailureSource::class, ReleaseCollectionDto::class, [
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

        try {
            $objectMapper->map(new RebindingCollectionFailureSource($mapperCache), ReleaseCollectionDto::class);
            self::fail('Expected rebound collection failure to fail.');
        } catch (MappingExecutionFailed $exception) {
            self::assertStringContainsString(RebindingCollectionFailureSource::class . '->' . ReleaseCollectionDto::class, $exception->getMessage());
            self::assertStringContainsString('string key sha256:' . substr(hash('sha256', "attacker-secret\n"), 0, 16), $exception->getMessage());
            self::assertStringContainsString(AccessToken::class, $exception->getMessage());
            self::assertStringNotContainsString('attacker-secret', $exception->getMessage());
            self::assertStringNotContainsString("\n", $exception->getMessage());
        }
    }

    public function testItRejectsAValidCollectionFailureFromAnIndependentMapping(): void
    {
        $definitions = [
            new MappingDefinition(Release::class, ReleaseDto::class),
            new MappingDefinition(ReleaseCollectionSource::class, ReleaseCollectionDto::class, [
                'releases' => MapRule::from('releases')->collection(Release::class, ReleaseDto::class),
            ]),
            new MappingDefinition(IndependentCollectionFailureSource::class, IndependentCollectionFailureDto::class, [
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

        try {
            $objectMapper->map(new IndependentCollectionFailureSource($mapperCache), IndependentCollectionFailureDto::class);
            self::fail('Expected an independent collection failure to be sanitized.');
        } catch (MappingExecutionFailed $exception) {
            self::assertStringContainsString(IndependentCollectionFailureSource::class . '->' . IndependentCollectionFailureDto::class, $exception->getMessage());
            self::assertStringNotContainsString(ReleaseCollectionSource::class . '->' . ReleaseCollectionDto::class, $exception->getMessage());
            self::assertStringNotContainsString('attacker-secret', $exception->getMessage());
        }
    }

    public function testItRejectsAnUnexecutedReachableCollectionFailure(): void
    {
        $definitions = [
            new MappingDefinition(Release::class, ReleaseDto::class),
            new MappingDefinition(ReleaseCollectionSource::class, ReleaseCollectionDto::class, [
                'releases' => MapRule::from('releases')->collection(Release::class, ReleaseDto::class),
            ]),
            new MappingDefinition(ReachableSiblingFailureSource::class, ReachableSiblingFailureDto::class, [
                'sibling'  => MapRule::from('sibling')->nested(ReleaseCollectionDto::class),
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

        try {
            $objectMapper->map(new ReachableSiblingFailureSource($mapperCache), ReachableSiblingFailureDto::class);
            self::fail('Expected an unexecuted sibling failure to be sanitized.');
        } catch (MappingExecutionFailed $exception) {
            self::assertStringContainsString(ReachableSiblingFailureSource::class . '->' . ReachableSiblingFailureDto::class, $exception->getMessage());
            self::assertStringNotContainsString(ReleaseCollectionSource::class . '->' . ReleaseCollectionDto::class, $exception->getMessage());
            self::assertStringNotContainsString('attacker-secret', $exception->getMessage());
        }
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

    public function testItPreparesNestedDependenciesBeforeCollectionIteration(): void
    {
        $definitions = [
            new MappingDefinition(Release::class, ReleaseDto::class),
            new MappingDefinition(RegistryCountingCollectionSource::class, ReleaseCollectionDto::class, [
                'releases' => MapRule::fromGetter('getReleases')->collection(Release::class, ReleaseDto::class),
            ]),
        ];
        $countingMappingRegistry  = new CountingMappingRegistry(new MappingRegistry($definitions));
        $valueTransformerRegistry = new ValueTransformerRegistry();
        $mapperCache              = new MapperCache(
            new MappingMetadataFactory($valueTransformerRegistry, mappingRegistry: $countingMappingRegistry),
            new PhpMapperGenerator(),
            $this->cacheDirectory,
            $valueTransformerRegistry,
            true,
            $countingMappingRegistry,
        );
        $objectMapper                     = new ObjectMapper($countingMappingRegistry, $mapperCache);
        $registryCountingCollectionSource = new RegistryCountingCollectionSource($countingMappingRegistry, [
            new Release('first'),
            new Release('second'),
            new Release('third'),
        ]);

        $releaseCollectionDto = $objectMapper->map($registryCountingCollectionSource, ReleaseCollectionDto::class);

        self::assertCount(3, $releaseCollectionDto->releases);
        self::assertSame($registryCountingCollectionSource->registryCallsWhenRead(), $countingMappingRegistry->getCalls());
    }

    public function testItRetainsPrecomputedGrandchildDependenciesAcrossNestedScopes(): void
    {
        $definitions = [
            new MappingDefinition(ThreeLevelLeafSource::class, ThreeLevelLeafDto::class),
            new MappingDefinition(ThreeLevelMiddleSource::class, ThreeLevelMiddleDto::class, [
                'child' => MapRule::from('child')->nested(ThreeLevelLeafDto::class),
            ]),
            new MappingDefinition(RegistryCountingRootSource::class, ThreeLevelRootDto::class, [
                'child' => MapRule::fromGetter('getChild')->nested(ThreeLevelMiddleDto::class),
            ]),
        ];
        $countingMappingRegistry  = new CountingMappingRegistry(new MappingRegistry($definitions));
        $valueTransformerRegistry = new ValueTransformerRegistry();
        $mapperCache              = new MapperCache(
            new MappingMetadataFactory($valueTransformerRegistry, mappingRegistry: $countingMappingRegistry),
            new PhpMapperGenerator(),
            $this->cacheDirectory,
            $valueTransformerRegistry,
            true,
            $countingMappingRegistry,
        );
        $objectMapper               = new ObjectMapper($countingMappingRegistry, $mapperCache);
        $registryCountingRootSource = new RegistryCountingRootSource(
            $countingMappingRegistry,
            new ThreeLevelMiddleSource(new ThreeLevelLeafSource('leaf')),
        );

        $threeLevelRootDto = $objectMapper->map($registryCountingRootSource, ThreeLevelRootDto::class);

        self::assertSame('leaf', $threeLevelRootDto->child->child->label);
        self::assertSame($registryCountingRootSource->registryCallsWhenRead(), $countingMappingRegistry->getCalls());
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

    public function testItKeepsStructuralRuntimeBindingsOutOfPublicMetadata(): void
    {
        $customMappingDefinition = new CustomMappingDefinition(Release::class, ReleaseDto::class, new StatefulReleaseMapper('release-'));
        $mappingDefinition       = new MappingDefinition(ReleaseCollectionSource::class, ReleaseCollectionDto::class, [
            'releases' => MapRule::from('releases')->collection(Release::class, ReleaseDto::class),
        ]);
        $mappingRegistry         = new MappingRegistry([
            $customMappingDefinition,
            $mappingDefinition,
        ]);
        $mappingMetadataFactory = new MappingMetadataFactory(mappingRegistry: $mappingRegistry);
        $mappingMetadata        = $mappingMetadataFactory->create($mappingDefinition);
        $nestedMappingMetadata  = $mappingMetadata->parameters[0]->nestedMapping;

        self::assertInstanceOf(NestedMappingMetadata::class, $nestedMappingMetadata);
        self::assertFalse((new ReflectionClass(NestedMappingMetadata::class))->hasProperty('dependencyRuntimeBinding'));
        self::assertFalse((new ReflectionClass(MappingMetadataFactory::class))->hasMethod('compiledDependencyBinding'));
        self::assertNull($mappingMetadataFactory->compiledDependencyMetadata($mappingMetadata, $nestedMappingMetadata));
    }

    public function testItReusesCompiledMetadataForDiamondDependencies(): void
    {
        $mappingDefinitions = [
            new MappingDefinition(ExactChild::class, ExactChildDto::class),
            new MappingDefinition(DiamondBranchSource::class, DiamondBranchDto::class, [
                'child' => MapRule::from('child')->nested(ExactChildDto::class),
            ]),
            new MappingDefinition(DiamondRootSource::class, DiamondRootDto::class, [
                'left'  => MapRule::from('left')->nested(DiamondBranchDto::class),
                'right' => MapRule::from('right')->nested(DiamondBranchDto::class),
            ]),
        ];
        $mappingRegistry        = new MappingRegistry($mappingDefinitions);
        $mappingMetadataFactory = new MappingMetadataFactory(mappingRegistry: $mappingRegistry);
        $mappingMetadata        = $mappingMetadataFactory->create($mappingDefinitions[2]);
        $leftNestedMapping      = $mappingMetadata->parameters[0]->nestedMapping;
        $rightNestedMapping     = $mappingMetadata->parameters[1]->nestedMapping;

        self::assertInstanceOf(NestedMappingMetadata::class, $leftNestedMapping);
        self::assertInstanceOf(NestedMappingMetadata::class, $rightNestedMapping);
        $leftBranchMetadata  = $mappingMetadataFactory->compiledDependencyMetadata($mappingMetadata, $leftNestedMapping);
        $rightBranchMetadata = $mappingMetadataFactory->compiledDependencyMetadata($mappingMetadata, $rightNestedMapping);
        self::assertInstanceOf(MappingMetadata::class, $leftBranchMetadata);
        self::assertSame($leftBranchMetadata, $rightBranchMetadata);

        $leafNestedMapping = $leftBranchMetadata->parameters[0]->nestedMapping;
        self::assertInstanceOf(NestedMappingMetadata::class, $leafNestedMapping);
        self::assertInstanceOf(
            MappingMetadata::class,
            $mappingMetadataFactory->compiledDependencyMetadata($leftBranchMetadata, $leafNestedMapping),
        );
    }

    public function testItRejectsAWrongFingerprintTraversalPairBeforeACycleCanRecurse(): void
    {
        $rootMappingDefinition = new MappingDefinition(IndirectCycleSourceA::class, IndirectCycleDtoA::class, [
            'child' => MapRule::from('child')->nested(IndirectCycleDtoB::class),
        ]);
        $childMappingDefinition = new MappingDefinition(IndirectCycleSourceB::class, IndirectCycleDtoB::class, [
            'child' => MapRule::from('child')->nested(IndirectCycleDtoC::class),
        ]);
        $leafMappingDefinition = new MappingDefinition(IndirectCycleSourceC::class, IndirectCycleDtoC::class, [
            'child' => MapRule::from('child')->nested(IndirectCycleDtoA::class),
        ]);
        $wrongThenCorrectDependencyRegistry = new WrongThenCorrectDependencyRegistry(
            new MappingRegistry([$rootMappingDefinition, $childMappingDefinition, $leafMappingDefinition]),
            IndirectCycleSourceA::class,
            IndirectCycleDtoA::class,
            new MappingDefinition(AccessToken::class, ApiAccessTokenDto::class),
        );

        try {
            (new MappingMetadataFactory(mappingRegistry: $wrongThenCorrectDependencyRegistry))->create($rootMappingDefinition);
            self::fail('Expected the wrong fingerprint traversal pair to fail.');
        } catch (MappingCompilationFailed $exception) {
            self::assertStringContainsString('resolved the wrong mapping pair', $exception->getMessage());
            self::assertStringNotContainsString('maximum function nesting', $exception->getMessage());
        }
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

    private function mapper(bool $generateOnDemand, MappingDefinitionInterface ...$definitions): ObjectMapper
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

    private function mapperWithProvider(
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

    private function mapperWithTransformers(
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
}

class ExactChild
{
    public function __construct(public string $value) {}
}

final class ForgedGetterSource
{
    public function getName(): string
    {
        throw new GeneratedMappingExecutionFailed(new stdClass());
    }
}

final class ExactChildSubclass extends ExactChild {}

final class ExactChildDto
{
    public function __construct(public string $value) {}
}

final class ExactHolderSource
{
    public function __construct(public ExactChild $child) {}
}

final class ExactHolderDto
{
    public function __construct(public ExactChildDto $child) {}
}

final class ExactCollectionSource
{
    /** @param list<ExactChild> $children */
    public function __construct(public array $children) {}
}

final class ExactCollectionDto
{
    /** @param list<ExactChildDto> $children */
    public function __construct(public array $children) {}
}

final class NestedReleaseCollectionSource
{
    public function __construct(public ReleaseCollectionSource $collection) {}
}

final class NestedReleaseCollectionDto
{
    public function __construct(public ReleaseCollectionDto $collection) {}
}

class PolymorphicTarget {}

final class PolymorphicTargetSubtype extends PolymorphicTarget {}

final class PolymorphicHolderSource
{
    /** @param list<ExactChild> $children */
    public function __construct(public ExactChild $child, public array $children) {}
}

final class PolymorphicHolderDto
{
    /** @param list<PolymorphicTarget> $children */
    public function __construct(public PolymorphicTarget $child, public array $children) {}
}

final class InvalidChildHolderDto
{
    public function __construct(public MissingTarget $token) {}
}

final class SecondInvalidChildHolderSource
{
    public function __construct(public AccessToken $token) {}
}

final class SecondInvalidChildHolderDto
{
    public function __construct(public MissingTarget $token) {}
}

final class MaliciousCollectionFailureSource
{
    /** @return list<Release> */
    public function getReleases(): array
    {
        throw new GeneratedMappingExecutionFailed(new stdClass());
    }
}

final readonly class RebindingCollectionFailureSource
{
    public function __construct(private MapperCache $mapperCache) {}

    /** @return list<Release> */
    public function getReleases(): array
    {
        try {
            $this->mapperCache->collectionElementTypeFailure(
                self::class,
                ReleaseCollectionDto::class,
                'releases',
                "attacker-secret\n",
                Release::class,
                new AccessToken('attacker-secret'),
            );
        } catch (GeneratedMappingExecutionFailed $exception) {
            throw new GeneratedMappingExecutionFailed($exception->context());
        }
    }
}

final readonly class IndependentCollectionFailureSource
{
    public function __construct(private MapperCache $mapperCache) {}

    /** @return list<Release> */
    public function getReleases(): array
    {
        return $this->mapperCache->collectionElementTypeFailure(
            ReleaseCollectionSource::class,
            ReleaseCollectionDto::class,
            'releases',
            'attacker-secret',
            Release::class,
            new AccessToken('attacker-secret'),
        );
    }
}

final class IndependentCollectionFailureDto
{
    /** @param list<ReleaseDto> $releases */
    public function __construct(public array $releases) {}
}

final class ReachableSiblingFailureSource
{
    public ?ReleaseCollectionSource $sibling = null;

    public function __construct(private readonly MapperCache $mapperCache) {}

    /** @return list<Release> */
    public function getReleases(): array
    {
        return $this->mapperCache->collectionElementTypeFailure(
            ReleaseCollectionSource::class,
            ReleaseCollectionDto::class,
            'releases',
            'attacker-secret',
            Release::class,
            new AccessToken('attacker-secret'),
        );
    }
}

final class ReachableSiblingFailureDto
{
    /** @param list<ReleaseDto> $releases */
    public function __construct(public ?ReleaseCollectionDto $sibling, public array $releases) {}
}

final readonly class FiberCollectionFailureSource
{
    public function __construct(
        private MapperCache $mapperCache,
        private bool $suspend,
        private int $key,
    ) {}

    /** @return list<Release> */
    public function getReleases(): array
    {
        if ($this->suspend) {
            Fiber::suspend();
        }

        return $this->mapperCache->collectionElementTypeFailure(
            self::class,
            FiberCollectionFailureDto::class,
            'releases',
            $this->key,
            Release::class,
            new AccessToken('fiber-secret'),
        );
    }
}

final class FiberCollectionFailureDto
{
    /** @param list<ReleaseDto> $releases */
    public function __construct(public array $releases) {}
}

final class RegistryCountingCollectionSource
{
    private int $registryCallsWhenRead = 0;

    /** @param list<Release> $releases */
    public function __construct(
        private readonly CountingMappingRegistry $countingMappingRegistry,
        private readonly array $releases,
    ) {}

    /** @return list<Release> */
    public function getReleases(): array
    {
        $this->registryCallsWhenRead = $this->countingMappingRegistry->getCalls();

        return $this->releases;
    }

    public function registryCallsWhenRead(): int
    {
        return $this->registryCallsWhenRead;
    }
}

final class CountingMappingRegistry implements MappingRegistryInterface
{
    private int $calls = 0;

    public function __construct(private readonly MappingRegistryInterface $mappingRegistry) {}

    public function get(string $source, string $target): MappingDefinitionInterface
    {
        ++$this->calls;

        return $this->mappingRegistry->get($source, $target);
    }

    public function getCalls(): int
    {
        return $this->calls;
    }

    public function all(): iterable
    {
        return $this->mappingRegistry->all();
    }
}

final class StatefulDependencyRegistry implements MappingRegistryInterface
{
    private int $dependencyReads = 0;

    /** @param class-string $dependencySource @param class-string $dependencyTarget */
    public function __construct(
        private readonly MappingRegistryInterface $mappingRegistry,
        private readonly string $dependencySource,
        private readonly string $dependencyTarget,
        private readonly MappingDefinitionInterface $mappingDefinition,
    ) {}

    public function get(string $source, string $target): MappingDefinitionInterface
    {
        if ($source !== $this->dependencySource || $target !== $this->dependencyTarget) {
            return $this->mappingRegistry->get($source, $target);
        }

        ++$this->dependencyReads;

        return 1 === $this->dependencyReads
            ? $this->mappingRegistry->get($source, $target)
            : $this->mappingDefinition;
    }

    public function all(): iterable
    {
        return $this->mappingRegistry->all();
    }
}

final class WarmupSnapshotRegistry implements MappingRegistryInterface
{
    private int $dependencyReads = 0;

    /** @param class-string $dependencySource @param class-string $dependencyTarget */
    public function __construct(
        private readonly MappingRegistryInterface $mappingRegistry,
        private readonly string $dependencySource,
        private readonly string $dependencyTarget,
        private readonly MappingDefinitionInterface|Throwable $replacement,
    ) {}

    public function get(string $source, string $target): MappingDefinitionInterface
    {
        if ($source !== $this->dependencySource || $target !== $this->dependencyTarget) {
            return $this->mappingRegistry->get($source, $target);
        }

        ++$this->dependencyReads;
        if (1 === $this->dependencyReads) {
            return $this->mappingRegistry->get($source, $target);
        }

        if ($this->replacement instanceof Throwable) {
            throw $this->replacement;
        }

        return $this->replacement;
    }

    public function dependencyReads(): int
    {
        return $this->dependencyReads;
    }

    public function all(): iterable
    {
        return $this->mappingRegistry->all();
    }
}

final class SequentialDependencyRegistry implements MappingRegistryInterface
{
    private int $dependencyReads = 0;

    /** @param class-string $dependencySource @param class-string $dependencyTarget */
    public function __construct(
        private readonly MappingRegistryInterface $mappingRegistry,
        private readonly string $dependencySource,
        private readonly string $dependencyTarget,
        private readonly MappingDefinitionInterface $mappingDefinition,
    ) {}

    public function get(string $source, string $target): MappingDefinitionInterface
    {
        if ($source !== $this->dependencySource || $target !== $this->dependencyTarget) {
            return $this->mappingRegistry->get($source, $target);
        }

        ++$this->dependencyReads;

        return 1 === $this->dependencyReads
            ? $this->mappingRegistry->get($source, $target)
            : $this->mappingDefinition;
    }

    public function all(): iterable
    {
        return $this->mappingRegistry->all();
    }
}

final class ThrowingAllMappingRegistry implements MappingRegistryInterface
{
    public function get(string $source, string $target): MappingDefinitionInterface
    {
        throw new RuntimeException('all-registry-secret');
    }

    public function all(): iterable
    {
        throw new RuntimeException('all-registry-secret');
    }
}

final readonly class HostileDependencyRegistry implements MappingRegistryInterface
{
    /** @param class-string $dependencySource @param class-string $dependencyTarget */
    public function __construct(
        private MappingRegistryInterface $mappingRegistry,
        private string $dependencySource,
        private string $dependencyTarget,
    ) {}

    public function get(string $source, string $target): MappingDefinitionInterface
    {
        if ($source === $this->dependencySource && $target === $this->dependencyTarget) {
            return new HostileMappingDefinition();
        }

        return $this->mappingRegistry->get($source, $target);
    }

    public function all(): iterable
    {
        return $this->mappingRegistry->all();
    }
}

final class HostileMappingDefinition implements MappingDefinitionInterface
{
    public function source(): string
    {
        throw new RuntimeException('hostile-source-secret');
    }

    public function target(): string
    {
        throw new RuntimeException('hostile-target-secret');
    }

    public function key(): string
    {
        throw new RuntimeException('hostile-key-secret');
    }
}

final class WrongThenCorrectDependencyRegistry implements MappingRegistryInterface
{
    private bool $returnedWrongPair = false;

    /** @param class-string $dependencySource @param class-string $dependencyTarget */
    public function __construct(
        private readonly MappingRegistryInterface $mappingRegistry,
        private readonly string $dependencySource,
        private readonly string $dependencyTarget,
        private readonly MappingDefinitionInterface $wrongPairMappingDefinition,
    ) {}

    public function get(string $source, string $target): MappingDefinitionInterface
    {
        if (! $this->returnedWrongPair
            && $source === $this->dependencySource
            && $target === $this->dependencyTarget) {
            $this->returnedWrongPair = true;

            return $this->wrongPairMappingDefinition;
        }

        return $this->mappingRegistry->get($source, $target);
    }

    public function all(): iterable
    {
        return $this->mappingRegistry->all();
    }
}

final class ReentrantCreateMappingRegistry implements MappingRegistryInterface
{
    private ?MappingMetadataFactory $mappingMetadataFactory = null;

    private ?MappingDefinition $mappingDefinition = null;

    private bool $reentered = false;

    /** @param class-string $dependencySource @param class-string $dependencyTarget */
    public function __construct(
        private readonly MappingRegistryInterface $mappingRegistry,
        private readonly string $dependencySource,
        private readonly string $dependencyTarget,
    ) {}

    public function configureReentry(MappingMetadataFactory $mappingMetadataFactory, MappingDefinition $mappingDefinition): void
    {
        $this->mappingMetadataFactory = $mappingMetadataFactory;
        $this->mappingDefinition      = $mappingDefinition;
    }

    public function get(string $source, string $target): MappingDefinitionInterface
    {
        if (! $this->reentered
            && $source === $this->dependencySource
            && $target === $this->dependencyTarget
            && $this->mappingMetadataFactory instanceof MappingMetadataFactory
            && $this->mappingDefinition instanceof MappingDefinition) {
            $this->reentered = true;
            $this->mappingMetadataFactory->create($this->mappingDefinition);
        }

        return $this->mappingRegistry->get($source, $target);
    }

    public function all(): iterable
    {
        return $this->mappingRegistry->all();
    }
}

final class FiberSuspendingMappingRegistry implements MappingRegistryInterface
{
    private bool $suspended = false;

    /** @param class-string $dependencySource @param class-string $dependencyTarget */
    public function __construct(
        private readonly MappingRegistryInterface $mappingRegistry,
        private readonly string $dependencySource,
        private readonly string $dependencyTarget,
    ) {}

    public function get(string $source, string $target): MappingDefinitionInterface
    {
        if (! $this->suspended
            && $source === $this->dependencySource
            && $target === $this->dependencyTarget
            && Fiber::getCurrent() instanceof Fiber) {
            $this->suspended = true;
            Fiber::suspend();
        }

        return $this->mappingRegistry->get($source, $target);
    }

    public function all(): iterable
    {
        return $this->mappingRegistry->all();
    }
}

final readonly class StatefulReleaseMapper implements CustomObjectMapperInterface
{
    public function __construct(private string $prefix) {}

    public function map(object $source): object
    {
        if (! $source instanceof Release) {
            throw new RuntimeException('Expected a release.');
        }

        return new ReleaseDto($this->prefix . $source->version);
    }
}

final readonly class StatefulLeafMapper implements CustomObjectMapperInterface
{
    public function __construct(private string $prefix) {}

    public function map(object $source): object
    {
        if (! $source instanceof ThreeLevelLeafSource) {
            throw new RuntimeException('Expected a three-level leaf.');
        }

        return new ThreeLevelLeafDto($this->prefix . $source->label);
    }
}

final class RegistryCountingRootSource
{
    private int $registryCallsWhenRead = 0;

    public function __construct(
        private readonly CountingMappingRegistry $countingMappingRegistry,
        private readonly ThreeLevelMiddleSource $threeLevelMiddleSource,
    ) {}

    public function getChild(): ThreeLevelMiddleSource
    {
        $this->registryCallsWhenRead = $this->countingMappingRegistry->getCalls();

        return $this->threeLevelMiddleSource;
    }

    public function registryCallsWhenRead(): int
    {
        return $this->registryCallsWhenRead;
    }
}

final class DiamondBranchSource
{
    public function __construct(public ExactChild $child) {}
}

final class DiamondBranchDto
{
    public function __construct(public ExactChildDto $child) {}
}

final class DiamondRootSource
{
    public function __construct(public DiamondBranchSource $left, public DiamondBranchSource $right) {}
}

final class DiamondRootDto
{
    public function __construct(public DiamondBranchDto $left, public DiamondBranchDto $right) {}
}

final class UnrelatedChildSource
{
    /** @param list<Release> $releases */
    public function __construct(public array $releases) {}
}

final readonly class CacheMapFailureSource
{
    public function __construct(
        private MapperCache $mapperCache,
        private MappingDefinition $mappingDefinition,
    ) {}

    /** @return list<Release> */
    public function getReleases(): array
    {
        $createChild = (static fn (mixed $releases): UnrelatedChildSource => new UnrelatedChildSource($releases));
        $this->mapperCache->map($this->mappingDefinition, $createChild([new AccessToken('attacker-secret')]));

        return [];
    }
}

final readonly class UnrelatedNestedDispatchSource
{
    public function __construct(private MapperCache $mapperCache) {}

    /** @return list<Release> */
    public function getReleases(): array
    {
        $createChild = (static fn (mixed $releases): UnrelatedChildSource => new UnrelatedChildSource($releases));
        $this->mapperCache->mapNested(
            $createChild([new AccessToken('attacker-secret')]),
            UnrelatedChildSource::class,
            ReleaseCollectionDto::class,
        );

        return [];
    }
}

final class NullableGetterHolderSource
{
    private int $reads = 0;

    public function __construct(private readonly ?ExactChild $exactChild) {}

    public function getChild(): ?ExactChild
    {
        ++$this->reads;

        return $this->exactChild;
    }

    public function readCount(): int
    {
        return $this->reads;
    }
}

final class NullableGetterHolderDto
{
    public function __construct(public ?ExactChildDto $child) {}
}

final class CollectionHelperCollisionSource
{
    /**
     * @param list<ExactChild> $items
     * @param list<ExactChild> $Items
     */
    public function __construct(public array $items, public array $Items) {}
}

final class CollectionHelperCollisionDto
{
    /**
     * @param list<ExactChildDto> $items
     * @param list<ExactChildDto> $Items
     */
    public function __construct(public array $items, public array $Items) {}
}
