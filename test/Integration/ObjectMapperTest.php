<?php

declare(strict_types=1);

namespace Sirix\ObjectMapperTest\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sirix\ObjectMapper\Contract\CustomObjectMapperInterface;
use Sirix\ObjectMapper\Contract\MappingDefinitionInterface;
use Sirix\ObjectMapper\Definition\CustomMappingDefinition;
use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Definition\MapRule;
use Sirix\ObjectMapper\Exception\MappingCompilationFailed;
use Sirix\ObjectMapper\Exception\MappingExecutionFailed;
use Sirix\ObjectMapper\Exception\MappingNotRegistered;
use Sirix\ObjectMapper\Generator\MapperCache;
use Sirix\ObjectMapper\Generator\PhpMapperGenerator;
use Sirix\ObjectMapper\Metadata\MappingMetadataFactory;
use Sirix\ObjectMapper\Runtime\MappingRegistry;
use Sirix\ObjectMapper\Runtime\ObjectMapper;
use Sirix\ObjectMapperTest\Support\ConventionalSource;
use Sirix\ObjectMapperTest\Support\ConventionalTarget;
use Sirix\ObjectMapperTest\Support\DefaultSource;
use Sirix\ObjectMapperTest\Support\DefaultTarget;
use Sirix\ObjectMapperTest\Support\IdTarget;
use Sirix\ObjectMapperTest\Support\MissingTarget;
use Sirix\ObjectMapperTest\Support\NameTarget;
use Sirix\ObjectMapperTest\Support\PrivateSource;
use Sirix\ObjectMapperTest\Support\ProfileSource;
use Sirix\ObjectMapperTest\Support\ProfileTarget;
use Sirix\ObjectMapperTest\Support\RulePrecedenceSource;
use Sirix\ObjectMapperTest\Support\ThrowingGetterSource;

use function bin2hex;
use function chmod;
use function fileperms;
use function glob;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
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
        $mapperCache = new MapperCache(
            new MappingMetadataFactory(),
            new PhpMapperGenerator(),
            $this->cacheDirectory,
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

    private function mapper(bool $generateOnDemand, MappingDefinitionInterface ...$definitions): ObjectMapper
    {
        return new ObjectMapper(
            new MappingRegistry($definitions),
            new MapperCache(
                new MappingMetadataFactory(),
                new PhpMapperGenerator(),
                $this->cacheDirectory,
                $generateOnDemand,
            ),
        );
    }
}
