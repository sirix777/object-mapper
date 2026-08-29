<?php

declare(strict_types=1);

namespace Sirix\ObjectMapperTest\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sirix\ObjectMapper\Contract\CustomObjectMapperInterface;
use Sirix\ObjectMapper\Definition\CustomMappingDefinition;
use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Definition\ProviderCustomMappingDefinition;
use Sirix\ObjectMapper\Exception\MappingNotRegistered;
use Sirix\ObjectMapper\Runtime\MappingRegistry;
use Sirix\ObjectMapperTest\Support\AbstractFixture;
use Sirix\ObjectMapperTest\Support\DefaultSource;
use Sirix\ObjectMapperTest\Support\DefaultTarget;
use stdClass;

use function iterator_to_array;

#[CoversClass(MappingRegistry::class)]
#[CoversClass(ProviderCustomMappingDefinition::class)]
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

    public function testItRetrievesAndIteratesProviderBackedDefinitions(): void
    {
        $providerCustomMappingDefinition = new ProviderCustomMappingDefinition(
            DefaultSource::class,
            DefaultTarget::class,
            'default-mapper',
        );
        $mappingRegistry = new MappingRegistry([$providerCustomMappingDefinition]);

        self::assertSame($providerCustomMappingDefinition, $mappingRegistry->get(DefaultSource::class, DefaultTarget::class));
        self::assertSame('default-mapper', $providerCustomMappingDefinition->mapperId());
        self::assertSame([
            $providerCustomMappingDefinition->key() => $providerCustomMappingDefinition,
        ], iterator_to_array($mappingRegistry->all()));
    }

    public function testItRejectsDuplicatePairsBetweenConventionalAndProviderBackedDefinitions(): void
    {
        $mappingDefinition               = new MappingDefinition(DefaultSource::class, DefaultTarget::class);
        $providerCustomMappingDefinition = new ProviderCustomMappingDefinition(
            DefaultSource::class,
            DefaultTarget::class,
            'default-mapper',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(MappingDefinition::class);
        $this->expectExceptionMessage(ProviderCustomMappingDefinition::class);

        new MappingRegistry([$mappingDefinition, $providerCustomMappingDefinition]);
    }

    public function testItRejectsDuplicatePairsBetweenDirectAndProviderBackedDefinitions(): void
    {
        $customMappingDefinition = new CustomMappingDefinition(
            DefaultSource::class,
            DefaultTarget::class,
            new class implements CustomObjectMapperInterface {
                public function map(object $source): object
                {
                    return new DefaultTarget(1);
                }
            },
        );
        $providerCustomMappingDefinition = new ProviderCustomMappingDefinition(
            DefaultSource::class,
            DefaultTarget::class,
            'default-mapper',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(CustomMappingDefinition::class);
        $this->expectExceptionMessage(ProviderCustomMappingDefinition::class);

        new MappingRegistry([$customMappingDefinition, $providerCustomMappingDefinition]);
    }

    public function testItRejectsAbstractClassesInDefinitions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MappingDefinition(AbstractFixture::class, DefaultTarget::class);
    }

    public function testItRejectsInvalidProviderBackedDefinitionSource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Mapping source class "' . AbstractFixture::class . '" must be concrete.');

        new ProviderCustomMappingDefinition(AbstractFixture::class, DefaultTarget::class, 'default-mapper');
    }

    public function testItRejectsInvalidProviderBackedDefinitionTarget(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Mapping target class "' . AbstractFixture::class . '" must be concrete.');

        new ProviderCustomMappingDefinition(DefaultSource::class, AbstractFixture::class, 'default-mapper');
    }

    public function testItRejectsEmptyProviderBackedDefinitionMapperId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Custom mapper identifier must not be empty.');

        new ProviderCustomMappingDefinition(DefaultSource::class, DefaultTarget::class, " \t\n");
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
