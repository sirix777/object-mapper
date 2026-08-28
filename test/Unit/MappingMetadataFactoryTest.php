<?php

declare(strict_types=1);

namespace Sirix\ObjectMapperTest\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sirix\ObjectMapper\Contract\ValueTransformerInterface;
use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Definition\MapRule;
use Sirix\ObjectMapper\Exception\MappingCompilationFailed;
use Sirix\ObjectMapper\Metadata\MappingMetadata;
use Sirix\ObjectMapper\Metadata\MappingMetadataFactory;
use Sirix\ObjectMapper\Metadata\SourceMember;
use Sirix\ObjectMapper\Metadata\TargetParameter;
use Sirix\ObjectMapper\Runtime\ValueTransformerRegistry;
use Sirix\ObjectMapperTest\Support\BooleanGetterSource;
use Sirix\ObjectMapperTest\Support\BooleanTarget;
use Sirix\ObjectMapperTest\Support\ByReferenceTransformTransformer;
use Sirix\ObjectMapperTest\Support\ConventionalSource;
use Sirix\ObjectMapperTest\Support\ConventionalTarget;
use Sirix\ObjectMapperTest\Support\DateTimeToAtomTransformer;
use Sirix\ObjectMapperTest\Support\DefaultSource;
use Sirix\ObjectMapperTest\Support\DefaultTarget;
use Sirix\ObjectMapperTest\Support\ExplicitMethodSource;
use Sirix\ObjectMapperTest\Support\ExplicitMethodTarget;
use Sirix\ObjectMapperTest\Support\GetterSource;
use Sirix\ObjectMapperTest\Support\IdTarget;
use Sirix\ObjectMapperTest\Support\IncompatibleProfileSource;
use Sirix\ObjectMapperTest\Support\IncompatibleTransformer;
use Sirix\ObjectMapperTest\Support\InheritedDateTimeTransformer;
use Sirix\ObjectMapperTest\Support\InheritedSource;
use Sirix\ObjectMapperTest\Support\InvalidMethodSource;
use Sirix\ObjectMapperTest\Support\InvalidProfileSource;
use Sirix\ObjectMapperTest\Support\MissingTarget;
use Sirix\ObjectMapperTest\Support\MixedOutputTransformer;
use Sirix\ObjectMapperTest\Support\MixedSource;
use Sirix\ObjectMapperTest\Support\MultipleArgumentTransformTransformer;
use Sirix\ObjectMapperTest\Support\NameTarget;
use Sirix\ObjectMapperTest\Support\NeverTransformTransformer;
use Sirix\ObjectMapperTest\Support\NonNullableNameTarget;
use Sirix\ObjectMapperTest\Support\NullableOutputTransformer;
use Sirix\ObjectMapperTest\Support\NullableSource;
use Sirix\ObjectMapperTest\Support\NullableUuidMethodSource;
use Sirix\ObjectMapperTest\Support\ParentTarget;
use Sirix\ObjectMapperTest\Support\PrivateSource;
use Sirix\ObjectMapperTest\Support\PrivateTransformTransformer;
use Sirix\ObjectMapperTest\Support\ProfileSource;
use Sirix\ObjectMapperTest\Support\ProfileTarget;
use Sirix\ObjectMapperTest\Support\RulePrecedenceSource;
use Sirix\ObjectMapperTest\Support\StaticGetterSource;
use Sirix\ObjectMapperTest\Support\StaticGetterTarget;
use Sirix\ObjectMapperTest\Support\StaticTransformTransformer;
use Sirix\ObjectMapperTest\Support\StringTarget;
use Sirix\ObjectMapperTest\Support\UntypedParameterTransformTransformer;
use Sirix\ObjectMapperTest\Support\UntypedReturnTransformTransformer;
use Sirix\ObjectMapperTest\Support\Uuid;
use Sirix\ObjectMapperTest\Support\UuidToStringTransformer;
use Sirix\ObjectMapperTest\Support\VariadicProfileTarget;
use Sirix\ObjectMapperTest\Support\VariadicTransformTransformer;
use Sirix\ObjectMapperTest\Support\VoidTransformTransformer;

use Sirix\ObjectMapperTest\Support\ZeroArgumentTransformTransformer;

use function array_map;
use function hash_file;

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

    public function testItResolvesExplicitPropertyAndGetterRulesBeforeConvention(): void
    {
        $mappingMetadata = $this->mappingMetadataFactory->create(new MappingDefinition(
            ProfileSource::class,
            ProfileTarget::class,
            [
                'id'    => MapRule::from('uuid'),
                'email' => MapRule::fromGetter('getPrimaryEmail'),
            ],
            ['passwordHash'],
        ));

        $idSourceMember    = $mappingMetadata->parameters[0]->sourceMember;
        $emailSourceMember = $mappingMetadata->parameters[1]->sourceMember;
        self::assertInstanceOf(SourceMember::class, $idSourceMember);
        self::assertInstanceOf(SourceMember::class, $emailSourceMember);

        self::assertSame('uuid', $idSourceMember->name);
        self::assertSame('property_rule', $idSourceMember->selection);
        self::assertSame('getPrimaryEmail', $emailSourceMember->name);
        self::assertSame('getter_rule', $emailSourceMember->selection);
    }

    public function testItGivesRulesPrecedenceOverSameNamePropertiesAndGetters(): void
    {
        $mappingMetadata = $this->mappingMetadataFactory->create(new MappingDefinition(
            RulePrecedenceSource::class,
            ProfileTarget::class,
            [
                'id'    => MapRule::from('uuid'),
                'email' => MapRule::fromGetter('getPrimaryEmail'),
            ],
            ['id'],
        ));

        self::assertSame('uuid', $mappingMetadata->parameters[0]->sourceMember?->name);
        self::assertSame('getPrimaryEmail', $mappingMetadata->parameters[1]->sourceMember?->name);
    }

    public function testItRejectsUnknownRuleTargetsAndInvalidConfiguredSelectors(): void
    {
        $this->assertCompilationFailsWithDefinition(new MappingDefinition(
            ProfileSource::class,
            ProfileTarget::class,
            [
                'unknown' => MapRule::from('uuid'),
            ],
            ['passwordHash'],
        ), 'Configured selector $uuid');
        $this->assertCompilationFailsWithDefinition(new MappingDefinition(
            InvalidProfileSource::class,
            IdTarget::class,
            [
                'id' => MapRule::from('privateId'),
            ],
        ), 'Configured property selector $privateId');
        $this->assertCompilationFailsWithDefinition(new MappingDefinition(
            InvalidProfileSource::class,
            IdTarget::class,
            [
                'id' => MapRule::from('staticName'),
            ],
        ), 'Configured property selector $staticName');
        $this->assertCompilationFailsWithDefinition(new MappingDefinition(
            InvalidProfileSource::class,
            NameTarget::class,
            [
                'name' => MapRule::fromGetter('getWithParameter'),
            ],
        ), 'Configured getter selector getWithParameter()');
    }

    public function testItRequiresIgnoredPropertiesToExistAndBePublic(): void
    {
        $this->assertCompilationFailsWithDefinition(new MappingDefinition(
            ProfileSource::class,
            ProfileTarget::class,
            [
                'id'    => MapRule::from('uuid'),
                'email' => MapRule::fromGetter('getPrimaryEmail'),
            ],
            ['missing'],
        ), 'ignored source property $missing does not exist');
        $this->assertCompilationFailsWithDefinition(new MappingDefinition(
            InvalidProfileSource::class,
            IdTarget::class,
            [
                'id' => MapRule::from('privateId'),
            ],
            ['privateId'],
        ), 'ignored source property $privateId must be public');
    }

    public function testItChecksRuleSelectedPropertiesForTypeCompatibility(): void
    {
        $this->assertCompilationFailsWithDefinition(new MappingDefinition(
            IncompatibleProfileSource::class,
            ProfileTarget::class,
            [
                'id'    => MapRule::from('uuid'),
                'email' => MapRule::fromGetter('getPrimaryEmail'),
            ],
            ['passwordHash'],
        ), 'Configured selector $uuid');
    }

    public function testItIncludesTheRuleSelectorForVariadicTargetParameters(): void
    {
        $this->assertCompilationFailsWithDefinition(new MappingDefinition(
            ProfileSource::class,
            VariadicProfileTarget::class,
            [
                'id' => MapRule::from('uuid'),
            ],
            ['passwordHash'],
        ), 'Configured selector $uuid');
    }

    public function testItCompilesAnExplicitMethodThroughARegisteredTransformer(): void
    {
        $mappingMetadata = (new MappingMetadataFactory(new ValueTransformerRegistry([
            new UuidToStringTransformer(),
            new DateTimeToAtomTransformer(),
        ])))->create(new MappingDefinition(
            ExplicitMethodSource::class,
            ExplicitMethodTarget::class,
            [
                'id'        => MapRule::fromMethod('getIdentifier')->through(UuidToStringTransformer::class),
                'createdAt' => MapRule::fromMethod('createdAt')->through(DateTimeToAtomTransformer::class),
                'slug'      => MapRule::fromMethod('slug'),
            ],
        ));

        self::assertSame('method_rule', $mappingMetadata->parameters[0]->sourceMember?->selection);
        self::assertSame(UuidToStringTransformer::class, $mappingMetadata->parameters[0]->transformer?->class);
        self::assertSame('method_rule', $mappingMetadata->parameters[2]->sourceMember?->selection);
        self::assertNull($mappingMetadata->parameters[2]->transformer);
    }

    public function testItRejectsAnonymousTransformerClassesAtDefinitionTime(): void
    {
        $anonymousTransformer = new class implements ValueTransformerInterface {
            public function transform(Uuid $uuid): string
            {
                return $uuid->toString();
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('named classes');

        MapRule::fromMethod('getIdentifier')->through($anonymousTransformer::class);
    }

    public function testItHashesTheFileThatDeclaresAnInheritedTransformMethod(): void
    {
        $mappingMetadata = (new MappingMetadataFactory(new ValueTransformerRegistry([
            new UuidToStringTransformer(),
            new InheritedDateTimeTransformer(),
        ])))->create(new MappingDefinition(
            ExplicitMethodSource::class,
            ExplicitMethodTarget::class,
            [
                'id'        => MapRule::fromMethod('getIdentifier')->through(UuidToStringTransformer::class),
                'createdAt' => MapRule::fromMethod('createdAt')->through(InheritedDateTimeTransformer::class),
                'slug'      => MapRule::fromMethod('slug'),
            ],
        ));

        $methodFileHash = hash_file('sha256', __DIR__ . '/../Support/ExternalTransformingParent.php');
        self::assertIsString($methodFileHash);
        self::assertSame($methodFileHash, $mappingMetadata->parameters[1]->transformer?->fileHash);
    }

    public function testItRejectsInvalidExplicitMethodsDuringMetadataCompilation(): void
    {
        foreach (['missing', 'hidden', 'staticMethod', 'argumentful', 'untyped'] as $method) {
            $this->assertCompilationFailsWithDefinition(new MappingDefinition(
                InvalidMethodSource::class,
                NameTarget::class,
                [
                    'name' => MapRule::fromMethod($method),
                ],
            ), $method . '()');
        }
    }

    public function testItRejectsUnregisteredAndInvalidTransformerMethodsDuringMetadataCompilation(): void
    {
        $this->assertCompilationFailsWithDefinition(new MappingDefinition(
            ExplicitMethodSource::class,
            ExplicitMethodTarget::class,
            [
                'id'        => MapRule::fromMethod('getIdentifier')->through(UuidToStringTransformer::class),
                'createdAt' => MapRule::fromMethod('createdAt')->through(DateTimeToAtomTransformer::class),
                'slug'      => MapRule::fromMethod('slug'),
            ],
        ), UuidToStringTransformer::class);

        foreach ([
            PrivateTransformTransformer::class,
            StaticTransformTransformer::class,
            VariadicTransformTransformer::class,
            ZeroArgumentTransformTransformer::class,
            MultipleArgumentTransformTransformer::class,
            ByReferenceTransformTransformer::class,
            UntypedParameterTransformTransformer::class,
            UntypedReturnTransformTransformer::class,
            VoidTransformTransformer::class,
            NeverTransformTransformer::class,
        ] as $transformerClass) {
            $this->assertTransformerCompilationFails($transformerClass, 'must');
        }
    }

    public function testItRejectsByReferenceTransformerParametersForPropertyAndMethodSelectors(): void
    {
        $mappingMetadataFactory = new MappingMetadataFactory(new ValueTransformerRegistry([
            new ByReferenceTransformTransformer(),
            new DateTimeToAtomTransformer(),
        ]));

        foreach ([
            new MappingDefinition(
                ProfileSource::class,
                ProfileTarget::class,
                [
                    'id'    => MapRule::from('uuid')->through(ByReferenceTransformTransformer::class),
                    'email' => MapRule::fromGetter('getPrimaryEmail'),
                ],
                ['passwordHash'],
            ),
            new MappingDefinition(
                ExplicitMethodSource::class,
                ExplicitMethodTarget::class,
                [
                    'id'        => MapRule::fromMethod('getIdentifier')->through(ByReferenceTransformTransformer::class),
                    'createdAt' => MapRule::fromMethod('createdAt')->through(DateTimeToAtomTransformer::class),
                    'slug'      => MapRule::fromMethod('slug'),
                ],
            ),
        ] as $mappingDefinition) {
            try {
                $mappingMetadataFactory->create($mappingDefinition);
                self::fail('Expected by-reference transformer parameter to fail metadata compilation.');
            } catch (MappingCompilationFailed $exception) {
                self::assertStringContainsString(ByReferenceTransformTransformer::class, $exception->getMessage());
                self::assertStringContainsString('by-value parameter', $exception->getMessage());
            }
        }
    }

    public function testItChecksBothTransformerCompatibilityEdges(): void
    {
        $this->assertTransformerCompilationFails(IncompatibleTransformer::class, 'transformer input type');
        $this->assertTransformerCompilationFails(MixedOutputTransformer::class, 'output type');
        $this->assertTransformerCompilationFails(NullableOutputTransformer::class, 'output type');

        $mappingMetadataFactory = new MappingMetadataFactory(new ValueTransformerRegistry([new UuidToStringTransformer()]));

        try {
            $mappingMetadataFactory->create(new MappingDefinition(
                NullableUuidMethodSource::class,
                NameTarget::class,
                [
                    'name' => MapRule::fromMethod('nullableIdentifier')->through(UuidToStringTransformer::class),
                ],
            ));
            self::fail('Expected nullable source input to fail transformer compatibility.');
        } catch (MappingCompilationFailed $exception) {
            self::assertStringContainsString('transformer input type', $exception->getMessage());
        }
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

    private function assertCompilationFailsWithDefinition(MappingDefinition $mappingDefinition, string $message): void
    {
        try {
            $this->mappingMetadataFactory->create($mappingDefinition);
            self::fail('Expected metadata compilation to fail.');
        } catch (MappingCompilationFailed $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    /** @param class-string<ValueTransformerInterface> $transformerClass */
    private function assertTransformerCompilationFails(string $transformerClass, string $message): void
    {
        $mappingMetadataFactory = new MappingMetadataFactory(new ValueTransformerRegistry([new $transformerClass()]));

        try {
            $mappingMetadataFactory->create(new MappingDefinition(
                ExplicitMethodSource::class,
                ExplicitMethodTarget::class,
                [
                    'id'        => MapRule::fromMethod('getIdentifier')->through($transformerClass),
                    'createdAt' => MapRule::fromMethod('createdAt')->through(DateTimeToAtomTransformer::class),
                    'slug'      => MapRule::fromMethod('slug'),
                ],
            ));
            self::fail('Expected transformer metadata compilation to fail.');
        } catch (MappingCompilationFailed $exception) {
            self::assertStringContainsString($transformerClass, $exception->getMessage());
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }
}
