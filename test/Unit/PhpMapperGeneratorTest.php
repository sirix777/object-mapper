<?php

declare(strict_types=1);

namespace Sirix\ObjectMapperTest\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Generator\PhpMapperGenerator;
use Sirix\ObjectMapper\Metadata\MappingMetadataFactory;
use Sirix\ObjectMapperTest\Support\ConventionalSource;
use Sirix\ObjectMapperTest\Support\ConventionalTarget;
use Sirix\ObjectMapperTest\Support\DefaultSource;
use Sirix\ObjectMapperTest\Support\DefaultTarget;

#[CoversClass(PhpMapperGenerator::class)]
final class PhpMapperGeneratorTest extends TestCase
{
    public function testItGeneratesStableNamedArgumentCodeForIdenticalMetadata(): void
    {
        $mappingMetadata    = (new MappingMetadataFactory())->create(new MappingDefinition(ConventionalSource::class, ConventionalTarget::class));
        $phpMapperGenerator = new PhpMapperGenerator();
        $key                = $phpMapperGenerator->cacheKey($mappingMetadata);

        self::assertSame($key, $phpMapperGenerator->cacheKey($mappingMetadata));
        self::assertSame($phpMapperGenerator->generate($mappingMetadata, $key), $phpMapperGenerator->generate($mappingMetadata, $key));
        self::assertStringContainsString('new \Sirix\ObjectMapperTest\Support\ConventionalTarget(', $phpMapperGenerator->generate($mappingMetadata, $key));
        self::assertStringContainsString('id: $source->id,', $phpMapperGenerator->generate($mappingMetadata, $key));
    }

    public function testItsCacheKeyChangesForDifferentMappingMetadata(): void
    {
        $mappingMetadataFactory   = new MappingMetadataFactory();
        $phpMapperGenerator       = new PhpMapperGenerator();

        self::assertNotSame(
            $phpMapperGenerator->cacheKey($mappingMetadataFactory->create(new MappingDefinition(ConventionalSource::class, ConventionalTarget::class))),
            $phpMapperGenerator->cacheKey($mappingMetadataFactory->create(new MappingDefinition(DefaultSource::class, DefaultTarget::class))),
        );
    }
}
