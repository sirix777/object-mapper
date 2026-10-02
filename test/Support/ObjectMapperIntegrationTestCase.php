<?php

declare(strict_types=1);

namespace Sirix\ObjectMapperTest\Integration;

use PHPUnit\Framework\TestCase;
use Sirix\ObjectMapper\Contract\CustomObjectMapperProviderInterface;
use Sirix\ObjectMapper\Contract\MappingDefinitionInterface;
use Sirix\ObjectMapper\Definition\CustomMappingDefinition;
use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Definition\MapRule;
use Sirix\ObjectMapper\Definition\ProviderCustomMappingDefinition;
use Sirix\ObjectMapper\Definition\SourceMatchMode;
use Sirix\ObjectMapper\Exception\MappingExecutionFailed;
use Sirix\ObjectMapper\Generator\MapperCache;
use Sirix\ObjectMapper\Generator\PhpMapperGenerator;
use Sirix\ObjectMapper\Metadata\MappingMetadataFactory;
use Sirix\ObjectMapper\Runtime\CustomMappingExecutor;
use Sirix\ObjectMapper\Runtime\MappingRegistry;
use Sirix\ObjectMapper\Runtime\ObjectMapper;
use Sirix\ObjectMapper\Runtime\ValueTransformerRegistry;
use Sirix\ObjectMapperTest\Support\CallbackLeafHolderDto;
use Sirix\ObjectMapperTest\Support\CallbackLeafHolderSource;
use Sirix\ObjectMapperTest\Support\CallbackRelease;
use Sirix\ObjectMapperTest\Support\CallbackReleaseMapper;
use Sirix\ObjectMapperTest\Support\CallbackReleaseProvider;
use Sirix\ObjectMapperTest\Support\ObservedReleaseCollectionsDto;
use Sirix\ObjectMapperTest\Support\ObservedReleaseCollectionsSource;
use Sirix\ObjectMapperTest\Support\Release;
use Sirix\ObjectMapperTest\Support\ReleaseDto;

use function bin2hex;
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

    /** @return array<string, array{bool}> */
    public static function collectionCacheModes(): array
    {
        return [
            'default'  => [false],
            'prepared' => [true],
        ];
    }

    public static function assertLeafCannotBorrowParentContext(MapperCache $mapperCache): void
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
}
