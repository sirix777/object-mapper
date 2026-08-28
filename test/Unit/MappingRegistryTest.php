<?php

declare(strict_types=1);

namespace Sirix\ObjectMapperTest\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sirix\ObjectMapper\Contract\CustomObjectMapperInterface;
use Sirix\ObjectMapper\Definition\CustomMappingDefinition;
use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Exception\MappingNotRegistered;
use Sirix\ObjectMapper\Runtime\MappingRegistry;
use Sirix\ObjectMapperTest\Support\AbstractFixture;
use Sirix\ObjectMapperTest\Support\DefaultSource;
use Sirix\ObjectMapperTest\Support\DefaultTarget;
use stdClass;

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

    public function testItRejectsDuplicatePairsAcrossDefinitionKinds(): void
    {
        $mappingDefinition        = new MappingDefinition(DefaultSource::class, DefaultTarget::class);
        $customMappingDefinition  = new CustomMappingDefinition(
            DefaultSource::class,
            DefaultTarget::class,
            new class implements CustomObjectMapperInterface {
                public function map(object $source): object
                {
                    return new DefaultTarget(1);
                }
            },
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(MappingDefinition::class);
        $this->expectExceptionMessage(CustomMappingDefinition::class);

        new MappingRegistry([$mappingDefinition, $customMappingDefinition]);
    }

    public function testItRejectsAbstractClassesInDefinitions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MappingDefinition(AbstractFixture::class, DefaultTarget::class);
    }

    public function testItRejectsNonStringIgnoredSourceEntries(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('valid string identifier');

        new MappingDefinition(DefaultSource::class, DefaultTarget::class, ignoredSource: $this->invalidIgnoredSource());
    }

    private function invalidIgnoredSource(): mixed
    {
        return [new stdClass()];
    }
}
