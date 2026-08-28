<?php

declare(strict_types=1);

namespace Sirix\ObjectMapperTest\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Definition\MapRule;
use Sirix\ObjectMapper\Generator\PhpMapperGenerator;
use Sirix\ObjectMapper\Metadata\MappingMetadataFactory;
use Sirix\ObjectMapperTest\Support\ConventionalSource;
use Sirix\ObjectMapperTest\Support\ConventionalTarget;
use Sirix\ObjectMapperTest\Support\DefaultSource;
use Sirix\ObjectMapperTest\Support\DefaultTarget;
use Sirix\ObjectMapperTest\Support\ProfileTarget;
use Sirix\ObjectMapperTest\Support\RulePrecedenceSource;

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

    public function testItsCacheKeyChangesWhenTheProfileChangesForTheSamePair(): void
    {
        $mappingMetadataFactory = new MappingMetadataFactory();
        $phpMapperGenerator     = new PhpMapperGenerator();

        $propertyAndGetterProfile = new MappingDefinition(
            RulePrecedenceSource::class,
            ProfileTarget::class,
            [
                'id'    => MapRule::from('uuid'),
                'email' => MapRule::fromGetter('getPrimaryEmail'),
            ],
            ['id'],
        );
        $conventionalProfile = new MappingDefinition(
            RulePrecedenceSource::class,
            ProfileTarget::class,
            [
                'id'    => MapRule::from('id'),
                'email' => MapRule::fromGetter('getEmail'),
            ],
            ['uuid'],
        );

        self::assertNotSame(
            $phpMapperGenerator->cacheKey($mappingMetadataFactory->create($propertyAndGetterProfile)),
            $phpMapperGenerator->cacheKey($mappingMetadataFactory->create($conventionalProfile)),
        );
    }
}
