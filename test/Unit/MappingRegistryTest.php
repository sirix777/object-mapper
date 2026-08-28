<?php

declare(strict_types=1);

namespace Sirix\ObjectMapperTest\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Exception\MappingNotRegistered;
use Sirix\ObjectMapper\Runtime\MappingRegistry;
use Sirix\ObjectMapperTest\Support\AbstractFixture;
use Sirix\ObjectMapperTest\Support\DefaultSource;
use Sirix\ObjectMapperTest\Support\DefaultTarget;

use function iterator_to_array;

#[CoversClass(MappingRegistry::class)]
final class MappingRegistryTest extends TestCase
{
    public function testItRetrievesAndIteratesExactDefinitions(): void
    {
        $mappingDefinition = new MappingDefinition(DefaultSource::class, DefaultTarget::class);
        $mappingRegistry   = new MappingRegistry([$mappingDefinition]);

        self::assertSame($mappingDefinition, $mappingRegistry->get(DefaultSource::class, DefaultTarget::class));
        self::assertSame([
            $mappingDefinition->key() => $mappingDefinition,
        ], iterator_to_array($mappingRegistry->all()));
    }

    public function testItRejectsUnknownAndDuplicatePairs(): void
    {
        $mappingDefinition = new MappingDefinition(DefaultSource::class, DefaultTarget::class);
        $mappingRegistry   = new MappingRegistry([$mappingDefinition]);

        $this->expectException(MappingNotRegistered::class);
        $mappingRegistry->get(DefaultTarget::class, DefaultSource::class);
    }

    public function testItRejectsDuplicateAndAbstractDefinitions(): void
    {
        $mappingDefinition = new MappingDefinition(DefaultSource::class, DefaultTarget::class);

        $this->expectException(InvalidArgumentException::class);
        new MappingRegistry([$mappingDefinition, $mappingDefinition]);
    }

    public function testItRejectsAbstractClassesInDefinitions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MappingDefinition(AbstractFixture::class, DefaultTarget::class);
    }
}
