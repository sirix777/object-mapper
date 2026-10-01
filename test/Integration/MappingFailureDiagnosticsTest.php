<?php

declare(strict_types=1);

namespace Sirix\ObjectMapperTest\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sirix\ObjectMapper\Contract\CustomObjectMapperInterface;
use Sirix\ObjectMapper\Contract\CustomObjectMapperProviderInterface;
use Sirix\ObjectMapper\Contract\ValueTransformerInterface;
use Sirix\ObjectMapper\Definition\CustomMappingDefinition;
use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Definition\MapRule;
use Sirix\ObjectMapper\Definition\ProviderCustomMappingDefinition;
use Sirix\ObjectMapper\Exception\MappingExecutionFailed;
use Sirix\ObjectMapper\Exception\MappingFailureReason;
use Sirix\ObjectMapper\Generator\MapperCache;
use Sirix\ObjectMapper\Generator\PhpMapperGenerator;
use Sirix\ObjectMapper\Metadata\MappingMetadataFactory;
use Sirix\ObjectMapper\Runtime\CustomMappingExecutor;
use Sirix\ObjectMapper\Runtime\MappingRegistry;
use Sirix\ObjectMapper\Runtime\ObjectMapper;
use Sirix\ObjectMapper\Runtime\ValueTransformerRegistry;
use Sirix\ObjectMapperTest\Support\Release;
use Sirix\ObjectMapperTest\Support\ReleaseCollectionDto;
use Sirix\ObjectMapperTest\Support\ReleaseCollectionSource;
use Sirix\ObjectMapperTest\Support\ReleaseDto;
use stdClass;

use function bin2hex;
use function is_dir;
use function random_bytes;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(MappingFailureReason::class)]
final class MappingFailureDiagnosticsTest extends TestCase
{
    private string $cacheDirectory;

    protected function setUp(): void
    {
        $this->cacheDirectory = sys_get_temp_dir() . '/object-mapper-diagnostics-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (! is_dir($this->cacheDirectory)) {
            return;
        }

        foreach (scandir($this->cacheDirectory) ?: [] as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                unlink($this->cacheDirectory . '/' . $entry);
            }
        }

        rmdir($this->cacheDirectory);
    }

    public function testItKeepsLegacyExceptionConstructionCompatible(): void
    {
        $runtimeException = new RuntimeException('previous');

        $legacy = new MappingExecutionFailed('legacy message', 7, $runtimeException);
        self::assertSame('legacy message', $legacy->getMessage());
        self::assertSame(7, $legacy->getCode());
        self::assertSame($runtimeException, $legacy->getPrevious());
        self::assertNull($legacy->reason());

        $reasoned = new MappingExecutionFailed('reasoned', 0, null, MappingFailureReason::CustomMapperFailed);
        self::assertSame(MappingFailureReason::CustomMapperFailed, $reasoned->reason());
    }

    public function testItReportsGeneratedGetterTransformerAndConstructorFailures(): void
    {
        $objectMapper = $this->mapper([new MappingDefinition(DiagnosticGetterSource::class, DiagnosticTarget::class)]);
        $this->assertFailsWith(static fn (): object => $objectMapper->map(new DiagnosticGetterSource(), DiagnosticTarget::class), MappingFailureReason::GeneratedMappingFailed);

        $transformerMapper = $this->mapper(
            [new MappingDefinition(DiagnosticSource::class, DiagnosticTarget::class, [
                'value' => MapRule::from('value')->through(DiagnosticThrowingTransformer::class),
            ])],
            new ValueTransformerRegistry([new DiagnosticThrowingTransformer()]),
        );
        $this->assertFailsWith(static fn (): object => $transformerMapper->map(new DiagnosticSource(), DiagnosticTarget::class), MappingFailureReason::GeneratedMappingFailed);

        $constructorMapper = $this->mapper([new MappingDefinition(DiagnosticSource::class, DiagnosticThrowingConstructorTarget::class)]);
        $this->assertFailsWith(static fn (): object => $constructorMapper->map(new DiagnosticSource(), DiagnosticThrowingConstructorTarget::class), MappingFailureReason::GeneratedMappingFailed);
    }

    public function testItReportsDirectCustomProviderUnavailableAndProviderResolutionFailures(): void
    {
        $throwingCustom = new class implements CustomObjectMapperInterface {
            public function map(object $source): object
            {
                throw new RuntimeException('secret-token custom');
            }
        };
        $objectMapper = $this->mapper([new CustomMappingDefinition(DiagnosticSource::class, DiagnosticTarget::class, $throwingCustom)]);
        $this->assertFailsWith(static fn (): object => $objectMapper->map(new DiagnosticSource(), DiagnosticTarget::class), MappingFailureReason::CustomMapperFailed);

        $missingProviderMapper = $this->mapper([new ProviderCustomMappingDefinition(DiagnosticSource::class, DiagnosticTarget::class, 'missing')]);
        $this->assertFailsWith(static fn (): object => $missingProviderMapper->map(new DiagnosticSource(), DiagnosticTarget::class), MappingFailureReason::ProviderUnavailable);

        $throwingProvider = new class implements CustomObjectMapperProviderInterface {
            public function get(string $mapperId): CustomObjectMapperInterface
            {
                throw new RuntimeException('secret-token provider');
            }
        };
        $providerMapper = $this->mapper([new ProviderCustomMappingDefinition(DiagnosticSource::class, DiagnosticTarget::class, 'id')], customObjectMapperProvider: $throwingProvider);
        $this->assertFailsWith(static fn (): object => $providerMapper->map(new DiagnosticSource(), DiagnosticTarget::class), MappingFailureReason::ProviderResolutionFailed);
    }

    public function testItReportsUnexpectedRootCustomTarget(): void
    {
        $wrongTarget = new class implements CustomObjectMapperInterface {
            public function map(object $source): object
            {
                return new stdClass();
            }
        };
        $mapper = $this->mapper([new CustomMappingDefinition(DiagnosticSource::class, DiagnosticTarget::class, $wrongTarget)]);

        $this->assertFailsWith(static fn (): object => $mapper->map(new DiagnosticSource(), DiagnosticTarget::class), MappingFailureReason::UnexpectedTarget);
    }

    public function testItReportsAuthenticatedCollectionElementType(): void
    {
        $mapper = $this->mapper([
            new MappingDefinition(Release::class, ReleaseDto::class),
            new MappingDefinition(ReleaseCollectionSource::class, ReleaseCollectionDto::class, [
                'releases' => MapRule::from('releases')->collection(Release::class, ReleaseDto::class),
            ]),
        ]);

        try {
            $createSource = (static fn (mixed $releases): ReleaseCollectionSource => new ReleaseCollectionSource($releases));
            $mapper->map($createSource([new stdClass()]), ReleaseCollectionDto::class);
            self::fail('Expected the collection element type to fail.');
        } catch (MappingExecutionFailed $exception) {
            self::assertSame(MappingFailureReason::CollectionElementType, $exception->reason());
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('secret-token', $exception->getMessage());
        }
    }

    public function testItDoesNotTrustForgedFailureReasons(): void
    {
        $objectMapper = $this->mapper([new MappingDefinition(DiagnosticForgedGetterSource::class, DiagnosticTarget::class)]);
        $this->assertFailsWith(static fn (): object => $objectMapper->map(new DiagnosticForgedGetterSource(), DiagnosticTarget::class), MappingFailureReason::GeneratedMappingFailed);

        $forgedCustom = new class implements CustomObjectMapperInterface {
            public function map(object $source): object
            {
                throw new MappingExecutionFailed('forged', reason: MappingFailureReason::GeneratedMappingFailed);
            }
        };
        $forgedCustomMapper = $this->mapper([new CustomMappingDefinition(DiagnosticSource::class, DiagnosticTarget::class, $forgedCustom)]);
        $this->assertFailsWith(static fn (): object => $forgedCustomMapper->map(new DiagnosticSource(), DiagnosticTarget::class), MappingFailureReason::CustomMapperFailed);
    }

    private function assertFailsWith(callable $operation, MappingFailureReason $mappingFailureReason): void
    {
        try {
            $operation();
            self::fail('Expected mapping execution to fail.');
        } catch (MappingExecutionFailed $exception) {
            self::assertSame($mappingFailureReason, $exception->reason());
            self::assertStringNotContainsString('secret-token', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    /** @param list<CustomMappingDefinition|MappingDefinition|ProviderCustomMappingDefinition> $definitions */
    private function mapper(
        array $definitions,
        ?ValueTransformerRegistry $valueTransformerRegistry = null,
        ?CustomObjectMapperProviderInterface $customObjectMapperProvider = null,
    ): ObjectMapper {
        $valueTransformerRegistry ??= new ValueTransformerRegistry();
        $mappingRegistry          = new MappingRegistry($definitions);
        $customMappingExecutor    = new CustomMappingExecutor($customObjectMapperProvider);

        return new ObjectMapper(
            $mappingRegistry,
            new MapperCache(
                new MappingMetadataFactory($valueTransformerRegistry, mappingRegistry: $mappingRegistry),
                new PhpMapperGenerator(),
                $this->cacheDirectory,
                $valueTransformerRegistry,
                true,
                $mappingRegistry,
                $customObjectMapperProvider,
                $customMappingExecutor,
            ),
            $customObjectMapperProvider,
            $customMappingExecutor,
        );
    }
}

final class DiagnosticSource
{
    public int $value = 1;
}

final class DiagnosticGetterSource
{
    public function getValue(): int
    {
        throw new RuntimeException('secret-token getter');
    }
}

final class DiagnosticForgedGetterSource
{
    public function getValue(): int
    {
        throw new MappingExecutionFailed('forged', reason: MappingFailureReason::CollectionElementType);
    }
}

final class DiagnosticTarget
{
    public function __construct(public int $value) {}
}

final readonly class DiagnosticThrowingConstructorTarget
{
    public function __construct(int $value)
    {
        throw new RuntimeException('secret-token constructor for ' . $value);
    }
}

final class DiagnosticThrowingTransformer implements ValueTransformerInterface
{
    public function transform(int $value): int
    {
        throw new RuntimeException('secret-token transformer');
    }
}
