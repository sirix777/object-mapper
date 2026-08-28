<?php

declare(strict_types=1);

namespace Sirix\ObjectMapperTest\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Exception\MappingCompilationFailed;
use Sirix\ObjectMapper\Metadata\MappingMetadata;
use Sirix\ObjectMapper\Metadata\MappingMetadataFactory;
use Sirix\ObjectMapper\Metadata\SourceMember;
use Sirix\ObjectMapper\Metadata\TargetParameter;
use Sirix\ObjectMapperTest\Support\BooleanGetterSource;
use Sirix\ObjectMapperTest\Support\BooleanTarget;
use Sirix\ObjectMapperTest\Support\ConventionalSource;
use Sirix\ObjectMapperTest\Support\ConventionalTarget;
use Sirix\ObjectMapperTest\Support\DefaultSource;
use Sirix\ObjectMapperTest\Support\DefaultTarget;
use Sirix\ObjectMapperTest\Support\GetterSource;
use Sirix\ObjectMapperTest\Support\IdTarget;
use Sirix\ObjectMapperTest\Support\InheritedSource;
use Sirix\ObjectMapperTest\Support\MissingTarget;
use Sirix\ObjectMapperTest\Support\MixedSource;
use Sirix\ObjectMapperTest\Support\NameTarget;
use Sirix\ObjectMapperTest\Support\NonNullableNameTarget;
use Sirix\ObjectMapperTest\Support\NullableSource;
use Sirix\ObjectMapperTest\Support\ParentTarget;
use Sirix\ObjectMapperTest\Support\PrivateSource;
use Sirix\ObjectMapperTest\Support\StaticGetterSource;
use Sirix\ObjectMapperTest\Support\StaticGetterTarget;
use Sirix\ObjectMapperTest\Support\StringTarget;

use function array_map;

#[CoversClass(MappingMetadataFactory::class)]
final class MappingMetadataFactoryTest extends TestCase
{
    private MappingMetadataFactory $mappingMetadataFactory;

    protected function setUp(): void
    {
        $this->mappingMetadataFactory = new MappingMetadataFactory();
    }

    public function testItResolvesPublicPropertiesInConstructorOrder(): void
    {
        $metadata = $this->metadata(ConventionalSource::class, ConventionalTarget::class);

        self::assertSame(['id', 'name', 'active'], array_map(
            static fn (TargetParameter $targetParameter): string => $targetParameter->sourceMember instanceof SourceMember
                ? $targetParameter->sourceMember->name
                : '',
            $metadata->parameters,
        ));
    }

    public function testItUsesGettersAndBooleanIsGettersWithTheRightTargetType(): void
    {
        self::assertSame('getName', $this->metadata(GetterSource::class, NameTarget::class)->parameters[0]->sourceMember?->name);
        self::assertSame('isActive', $this->metadata(BooleanGetterSource::class, BooleanTarget::class)->parameters[0]->sourceMember?->name);

        $this->expectException(MappingCompilationFailed::class);
        $this->mappingMetadataFactory->create(new MappingDefinition(BooleanGetterSource::class, StringTarget::class));
    }

    public function testItOmitsUnresolvedParametersWithDefaults(): void
    {
        $metadata = $this->metadata(DefaultSource::class, DefaultTarget::class);

        self::assertNull($metadata->parameters[1]->sourceMember);
        self::assertTrue($metadata->parameters[1]->hasDefault);
    }

    public function testItRejectsMissingRequiredAndInaccessibleMembers(): void
    {
        $this->assertCompilationFails(DefaultSource::class, MissingTarget::class, 'No safe readable source member');
        $this->assertCompilationFails(PrivateSource::class, IdTarget::class, 'No safe readable source member');
    }

    public function testItRejectsNullableAndMixedValuesForNarrowerTargets(): void
    {
        $this->assertCompilationFails(NullableSource::class, NonNullableNameTarget::class);
        $this->assertCompilationFails(MixedSource::class, NonNullableNameTarget::class);
    }

    public function testItAcceptsAClassSubtype(): void
    {
        $metadata = $this->metadata(InheritedSource::class, ParentTarget::class);

        self::assertSame('value', $metadata->parameters[0]->sourceMember?->name);
    }

    public function testItRejectsUnmappedPublicSourceProperties(): void
    {
        $this->assertCompilationFails(
            ConventionalSource::class,
            NameTarget::class,
            'public source property $id is not mapped',
        );
    }

    public function testItResolvesStaticGetterTypesAgainstTheRegisteredSourceClass(): void
    {
        $metadata = $this->metadata(StaticGetterSource::class, StaticGetterTarget::class);

        self::assertSame('getValue', $metadata->parameters[0]->sourceMember?->name);
    }

    /**
     * @param class-string $source
     * @param class-string $target
     */
    private function metadata(string $source, string $target): MappingMetadata
    {
        return $this->mappingMetadataFactory->create(new MappingDefinition($source, $target));
    }

    /**
     * @param class-string $source
     * @param class-string $target
     */
    private function assertCompilationFails(string $source, string $target, ?string $message = null): void
    {
        try {
            $this->mappingMetadataFactory->create(new MappingDefinition($source, $target));
            self::fail('Expected metadata compilation to fail.');
        } catch (MappingCompilationFailed $exception) {
            if (null !== $message) {
                self::assertStringContainsString($message, $exception->getMessage());
            } else {
                self::addToAssertionCount(1);
            }
        }
    }
}
