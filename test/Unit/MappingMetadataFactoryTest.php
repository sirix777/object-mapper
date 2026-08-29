<?php

declare(strict_types=1);

namespace Sirix\ObjectMapperTest\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sirix\ObjectMapper\Contract\MappingDefinitionInterface;
use Sirix\ObjectMapper\Contract\MappingRegistryInterface;
use Sirix\ObjectMapper\Contract\ValueTransformerInterface;
use Sirix\ObjectMapper\Definition\CustomMappingDefinition;
use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Definition\MapRule;
use Sirix\ObjectMapper\Definition\ProviderCustomMappingDefinition;
use Sirix\ObjectMapper\Exception\MappingCompilationFailed;
use Sirix\ObjectMapper\Metadata\MappingMetadata;
use Sirix\ObjectMapper\Metadata\MappingMetadataFactory;
use Sirix\ObjectMapper\Metadata\NestedMappingMetadata;
use Sirix\ObjectMapper\Metadata\SourceMember;
use Sirix\ObjectMapper\Metadata\TargetParameter;
use Sirix\ObjectMapper\Runtime\MappingRegistry;
use Sirix\ObjectMapper\Runtime\ValueTransformerRegistry;
use Sirix\ObjectMapperTest\Support\AccessToken;
use Sirix\ObjectMapperTest\Support\ApiAccessTokenDto;
use Sirix\ObjectMapperTest\Support\BooleanGetterSource;
use Sirix\ObjectMapperTest\Support\BooleanTarget;
use Sirix\ObjectMapperTest\Support\ByReferenceTransformTransformer;
use Sirix\ObjectMapperTest\Support\ConventionalSource;
use Sirix\ObjectMapperTest\Support\ConventionalTarget;
use Sirix\ObjectMapperTest\Support\CustomChildDto;
use Sirix\ObjectMapperTest\Support\CustomChildHolderDto;
use Sirix\ObjectMapperTest\Support\CustomChildHolderSource;
use Sirix\ObjectMapperTest\Support\CustomChildSource;
use Sirix\ObjectMapperTest\Support\DateTimeToAtomTransformer;
use Sirix\ObjectMapperTest\Support\DefaultNestedTokenHolderDto;
use Sirix\ObjectMapperTest\Support\DefaultSource;
use Sirix\ObjectMapperTest\Support\DefaultTarget;
use Sirix\ObjectMapperTest\Support\ExplicitMethodSource;
use Sirix\ObjectMapperTest\Support\ExplicitMethodTarget;
use Sirix\ObjectMapperTest\Support\GetterSource;
use Sirix\ObjectMapperTest\Support\IdTarget;
use Sirix\ObjectMapperTest\Support\IncompatibleProfileSource;
use Sirix\ObjectMapperTest\Support\IncompatibleTransformer;
use Sirix\ObjectMapperTest\Support\InheritedCustomChildMapper;
use Sirix\ObjectMapperTest\Support\InheritedDateTimeTransformer;
use Sirix\ObjectMapperTest\Support\InheritedSource;
use Sirix\ObjectMapperTest\Support\IntersectionTypedTokenHolderSource;
use Sirix\ObjectMapperTest\Support\InvalidMethodSource;
use Sirix\ObjectMapperTest\Support\InvalidProfileSource;
use Sirix\ObjectMapperTest\Support\IterableReleaseCollectionDto;
use Sirix\ObjectMapperTest\Support\IterableReleaseCollectionSource;
use Sirix\ObjectMapperTest\Support\MissingTarget;
use Sirix\ObjectMapperTest\Support\MixedOutputTransformer;
use Sirix\ObjectMapperTest\Support\MixedSource;
use Sirix\ObjectMapperTest\Support\MultipleArgumentTransformTransformer;
use Sirix\ObjectMapperTest\Support\NameTarget;
use Sirix\ObjectMapperTest\Support\NeverTransformTransformer;
use Sirix\ObjectMapperTest\Support\NonNullableNameTarget;
use Sirix\ObjectMapperTest\Support\NullableOutputTransformer;
use Sirix\ObjectMapperTest\Support\NullableReleaseCollectionDto;
use Sirix\ObjectMapperTest\Support\NullableReleaseCollectionSource;
use Sirix\ObjectMapperTest\Support\NullableSource;
use Sirix\ObjectMapperTest\Support\NullableTokenHolderDto;
use Sirix\ObjectMapperTest\Support\NullableTokenHolderSource;
use Sirix\ObjectMapperTest\Support\NullableUuidMethodSource;
use Sirix\ObjectMapperTest\Support\ParentTarget;
use Sirix\ObjectMapperTest\Support\PrivateSource;
use Sirix\ObjectMapperTest\Support\PrivateTransformTransformer;
use Sirix\ObjectMapperTest\Support\ProfileSource;
use Sirix\ObjectMapperTest\Support\ProfileTarget;
use Sirix\ObjectMapperTest\Support\RecordingCustomChildMapper;
use Sirix\ObjectMapperTest\Support\Release;
use Sirix\ObjectMapperTest\Support\ReleaseCollectionDto;
use Sirix\ObjectMapperTest\Support\ReleaseCollectionSource;
use Sirix\ObjectMapperTest\Support\ReleaseDto;
use Sirix\ObjectMapperTest\Support\RulePrecedenceSource;
use Sirix\ObjectMapperTest\Support\StaticGetterSource;
use Sirix\ObjectMapperTest\Support\StaticGetterTarget;
use Sirix\ObjectMapperTest\Support\StaticTransformTransformer;
use Sirix\ObjectMapperTest\Support\StringTarget;
use Sirix\ObjectMapperTest\Support\TokenHolderDto;
use Sirix\ObjectMapperTest\Support\TokenHolderSource;
use Sirix\ObjectMapperTest\Support\UnionTypedTokenHolderSource;
use Sirix\ObjectMapperTest\Support\UntypedParameterTransformTransformer;
use Sirix\ObjectMapperTest\Support\UntypedReturnTransformTransformer;
use Sirix\ObjectMapperTest\Support\UntypedTokenHolderSource;
use Sirix\ObjectMapperTest\Support\Uuid;
use Sirix\ObjectMapperTest\Support\UuidToStringTransformer;
use Sirix\ObjectMapperTest\Support\VariadicProfileTarget;
use Sirix\ObjectMapperTest\Support\VariadicTransformTransformer;
use Sirix\ObjectMapperTest\Support\VoidTransformTransformer;
use Sirix\ObjectMapperTest\Support\WrongTypedTokenHolderSource;

use Sirix\ObjectMapperTest\Support\ZeroArgumentTransformTransformer;

use function array_map;
use function hash;
use function hash_file;
use function json_encode;

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

    public function testItCompilesExactNestedAndCollectionDependencies(): void
    {
        $child                  = new MappingDefinition(AccessToken::class, ApiAccessTokenDto::class);
        $release                = new MappingDefinition(Release::class, ReleaseDto::class);
        $mappingMetadataFactory = $this->structuralFactory(
            $child,
            $release,
            new MappingDefinition(TokenHolderSource::class, TokenHolderDto::class, [
                'token' => MapRule::from('token')->nested(ApiAccessTokenDto::class),
            ]),
            new MappingDefinition(ReleaseCollectionSource::class, ReleaseCollectionDto::class, [
                'releases' => MapRule::from('releases')->collection(Release::class, ReleaseDto::class),
            ]),
        );

        $nested = $mappingMetadataFactory->create(new MappingDefinition(TokenHolderSource::class, TokenHolderDto::class, [
            'token' => MapRule::from('token')->nested(ApiAccessTokenDto::class),
        ]))->parameters[0]->nestedMapping;
        $collection = $mappingMetadataFactory->create(new MappingDefinition(ReleaseCollectionSource::class, ReleaseCollectionDto::class, [
            'releases' => MapRule::from('releases')->collection(Release::class, ReleaseDto::class),
        ]))->parameters[0]->nestedMapping;

        self::assertNotNull($nested);
        self::assertNotNull($collection);
        self::assertSame('nested', $nested->operation);
        self::assertSame(AccessToken::class, $nested->source);
        self::assertSame(ApiAccessTokenDto::class, $nested->target);
        self::assertSame('collection', $collection->operation);
        self::assertSame(Release::class, $collection->elementSource);
        self::assertSame(ReleaseDto::class, $collection->elementTarget);
    }

    public function testItCompilesNullableStructuralMappingsAndDoesNotExecuteCustomChildren(): void
    {
        $recordingCustomChildMapper  = new RecordingCustomChildMapper();
        $mappingMetadataFactory      = $this->structuralFactory(
            new MappingDefinition(AccessToken::class, ApiAccessTokenDto::class),
            new MappingDefinition(Release::class, ReleaseDto::class),
            new CustomMappingDefinition(CustomChildSource::class, CustomChildDto::class, $recordingCustomChildMapper),
            new MappingDefinition(NullableTokenHolderSource::class, NullableTokenHolderDto::class, [
                'token' => MapRule::from('token')->nested(ApiAccessTokenDto::class),
            ]),
            new MappingDefinition(NullableReleaseCollectionSource::class, NullableReleaseCollectionDto::class, [
                'releases' => MapRule::from('releases')->collection(Release::class, ReleaseDto::class),
            ]),
            new MappingDefinition(CustomChildHolderSource::class, CustomChildHolderDto::class, [
                'child' => MapRule::from('child')->nested(CustomChildDto::class),
            ]),
        );

        $nullableToken = $mappingMetadataFactory->create(new MappingDefinition(NullableTokenHolderSource::class, NullableTokenHolderDto::class, [
            'token' => MapRule::from('token')->nested(ApiAccessTokenDto::class),
        ]))->parameters[0]->nestedMapping;
        $nullableCollection = $mappingMetadataFactory->create(new MappingDefinition(NullableReleaseCollectionSource::class, NullableReleaseCollectionDto::class, [
            'releases' => MapRule::from('releases')->collection(Release::class, ReleaseDto::class),
        ]))->parameters[0]->nestedMapping;
        self::assertNotNull($nullableToken);
        self::assertNotNull($nullableCollection);
        self::assertTrue($nullableToken->nullable);
        self::assertTrue($nullableCollection->nullable);
        self::assertSame(0, $recordingCustomChildMapper->invocations);
    }

    public function testItRejectsStructuralNullableMismatchesAndDoesNotUseTargetDefaultsAsFallbacks(): void
    {
        $nullableMismatch = new MappingDefinition(NullableTokenHolderSource::class, TokenHolderDto::class, [
            'token' => MapRule::from('token')->nested(ApiAccessTokenDto::class),
        ]);
        $this->assertStructuralFailure($this->structuralFactory(
            new MappingDefinition(AccessToken::class, ApiAccessTokenDto::class),
            $nullableMismatch,
        ), $nullableMismatch, 'incompatible nullability');

        $defaultTarget = new MappingDefinition(WrongTypedTokenHolderSource::class, DefaultNestedTokenHolderDto::class, [
            'token' => MapRule::from('token')->nested(ApiAccessTokenDto::class),
        ]);
        $this->assertStructuralFailure(
            $this->structuralFactory($defaultTarget),
            $defaultTarget,
            'requires registered mapping',
        );
    }

    public function testItRejectsUnionIntersectionAndUntypedNestedSources(): void
    {
        foreach ([
            UnionTypedTokenHolderSource::class        => 'concrete named class type',
            IntersectionTypedTokenHolderSource::class => 'concrete named class type',
            UntypedTokenHolderSource::class           => 'typed source property',
        ] as $source => $expected) {
            $definition = new MappingDefinition($source, TokenHolderDto::class, [
                'token' => MapRule::from('token')->nested(ApiAccessTokenDto::class),
            ]);

            $this->assertStructuralFailure($this->structuralFactory($definition), $definition, $expected);
        }
    }

    public function testItRejectsIterableCollectionsInsteadOfSilentlyTreatingThemAsArrays(): void
    {
        $iterableSource = new MappingDefinition(IterableReleaseCollectionSource::class, ReleaseCollectionDto::class, [
            'releases' => MapRule::from('releases')->collection(Release::class, ReleaseDto::class),
        ]);
        $iterableTarget = new MappingDefinition(ReleaseCollectionSource::class, IterableReleaseCollectionDto::class, [
            'releases' => MapRule::from('releases')->collection(Release::class, ReleaseDto::class),
        ]);

        foreach ([$iterableSource, $iterableTarget] as $definition) {
            $this->assertStructuralFailure($this->structuralFactory($definition), $definition, 'requires array or ?array', 'releases');
        }
    }

    public function testItRejectsNestedTargetsThatCannotBeAssignedToTheConstructorParameter(): void
    {
        $mappingDefinition = new MappingDefinition(TokenHolderSource::class, TokenHolderDto::class, [
            'token' => MapRule::from('token')->nested(CustomChildDto::class),
        ]);

        $this->assertStructuralFailure($this->structuralFactory(
            new CustomMappingDefinition(AccessToken::class, CustomChildDto::class, new RecordingCustomChildMapper()),
            $mappingDefinition,
        ), $mappingDefinition, 'not assignable to target type');
    }

    public function testItFingerprintsTheFileThatDeclaresAnInheritedCustomMapperMethod(): void
    {
        $customMappingDefinition = new CustomMappingDefinition(
            CustomChildSource::class,
            CustomChildDto::class,
            new InheritedCustomChildMapper(),
        );
        $mappingDefinition = new MappingDefinition(CustomChildHolderSource::class, CustomChildHolderDto::class, [
            'child' => MapRule::from('child')->nested(CustomChildDto::class),
        ]);

        $fingerprint = $this->structuralFactory($customMappingDefinition, $mappingDefinition)
            ->create($mappingDefinition)
            ->parameters[0]
            ->nestedMapping?->dependencyFingerprint
        ;
        self::assertIsString($fingerprint);

        $fixtureHash = hash_file('sha256', __DIR__ . '/../Support/Fixtures.php');
        $methodHash  = hash_file('sha256', __DIR__ . '/../Support/ExternalCustomMapperParent.php');
        self::assertIsString($fixtureHash);
        self::assertIsString($methodHash);
        self::assertSame(hash('sha256', json_encode([
            'pair'                    => CustomChildSource::class . '->' . CustomChildDto::class,
            'kind'                    => 'custom',
            'sourceFileHash'          => $fixtureHash,
            'targetFileHash'          => $fixtureHash,
            'mapperClass'             => InheritedCustomChildMapper::class,
            'mapperFileHash'          => $fixtureHash,
            'mapperMapMethodFileHash' => $methodHash,
        ], JSON_THROW_ON_ERROR)), $fingerprint);
    }

    public function testItFingerprintsProviderCustomDependenciesByOpaqueMapperId(): void
    {
        $mappingDefinition = new MappingDefinition(CustomChildHolderSource::class, CustomChildHolderDto::class, [
            'child' => MapRule::from('child')->nested(CustomChildDto::class),
        ]);
        $firstChild = new ProviderCustomMappingDefinition(
            CustomChildSource::class,
            CustomChildDto::class,
            'custom-child-v1',
        );
        $secondChild = new ProviderCustomMappingDefinition(
            CustomChildSource::class,
            CustomChildDto::class,
            'custom-child-v2',
        );

        $firstFingerprint = $this->structuralFactory($firstChild, $mappingDefinition)
            ->create($mappingDefinition)
            ->parameters[0]
            ->nestedMapping?->dependencyFingerprint
        ;
        $secondFingerprint = $this->structuralFactory($secondChild, $mappingDefinition)
            ->create($mappingDefinition)
            ->parameters[0]
            ->nestedMapping?->dependencyFingerprint
        ;

        $fixtureHash = hash_file('sha256', __DIR__ . '/../Support/Fixtures.php');
        self::assertIsString($fixtureHash);
        self::assertSame(hash('sha256', json_encode([
            'pair'           => CustomChildSource::class . '->' . CustomChildDto::class,
            'kind'           => 'provider_custom',
            'sourceFileHash' => $fixtureHash,
            'targetFileHash' => $fixtureHash,
            'mapperId'       => 'custom-child-v1',
        ], JSON_THROW_ON_ERROR)), $firstFingerprint);
        self::assertNotSame($firstFingerprint, $secondFingerprint);
    }

    public function testItFingerprintsProviderCustomCollectionDependenciesByOpaqueMapperId(): void
    {
        $mappingDefinition = new MappingDefinition(ReleaseCollectionSource::class, ReleaseCollectionDto::class, [
            'releases' => MapRule::from('releases')->collection(Release::class, ReleaseDto::class),
        ]);
        $providerCustomMappingDefinition = new ProviderCustomMappingDefinition(
            Release::class,
            ReleaseDto::class,
            'release-collection-v1',
        );
        $mappingMetadataFactory = $this->structuralFactory($providerCustomMappingDefinition, $mappingDefinition);
        $mappingMetadata        = $mappingMetadataFactory->create($mappingDefinition);
        $collectionMapping      = $mappingMetadata->parameters[0]->nestedMapping;

        self::assertInstanceOf(NestedMappingMetadata::class, $collectionMapping);
        self::assertSame('collection', $collectionMapping->operation);
        self::assertTrue($mappingMetadataFactory->hasCompiledCustomDependency(
            $mappingMetadata,
            $collectionMapping,
        ));

        $fixtureHash = hash_file('sha256', __DIR__ . '/../Support/Fixtures.php');
        self::assertIsString($fixtureHash);
        self::assertSame(hash('sha256', json_encode([
            'pair'           => Release::class . '->' . ReleaseDto::class,
            'kind'           => 'provider_custom',
            'sourceFileHash' => $fixtureHash,
            'targetFileHash' => $fixtureHash,
            'mapperId'       => 'release-collection-v1',
        ], JSON_THROW_ON_ERROR)), $collectionMapping->dependencyFingerprint);
    }

    public function testItRecomputesDependencyFingerprintsForSeparateMetadataCompilations(): void
    {
        $firstChild  = new MappingDefinition(AccessToken::class, ApiAccessTokenDto::class);
        $secondChild = new MappingDefinition(AccessToken::class, ApiAccessTokenDto::class, [
            'value' => MapRule::from('value'),
        ]);
        $registry = new class($firstChild) implements MappingRegistryInterface {
            public function __construct(public MappingDefinition $dependency) {}

            public function get(string $source, string $target): MappingDefinitionInterface
            {
                return $this->dependency;
            }

            public function all(): iterable
            {
                return [$this->dependency];
            }
        };
        $mappingMetadataFactory = new MappingMetadataFactory(new ValueTransformerRegistry(), mappingRegistry: $registry);
        $parent                 = new MappingDefinition(TokenHolderSource::class, TokenHolderDto::class, [
            'token' => MapRule::from('token')->nested(ApiAccessTokenDto::class),
        ]);

        $firstFingerprint     = $mappingMetadataFactory->create($parent)->parameters[0]->nestedMapping?->dependencyFingerprint;
        $registry->dependency = $secondChild;
        $secondFingerprint    = $mappingMetadataFactory->create($parent)->parameters[0]->nestedMapping?->dependencyFingerprint;

        self::assertIsString($firstFingerprint);
        self::assertIsString($secondFingerprint);
        self::assertNotSame($firstFingerprint, $secondFingerprint);
    }

    public function testItRejectsMissingAndWrongExactNestedDependenciesWithSafeContext(): void
    {
        $definition = new MappingDefinition(TokenHolderSource::class, TokenHolderDto::class, [
            'token' => MapRule::from('token')->nested(ApiAccessTokenDto::class),
        ]);
        $this->assertStructuralFailure($this->structuralFactory($definition), $definition, 'requires registered mapping');

        $wrong = new MappingDefinition(WrongTypedTokenHolderSource::class, TokenHolderDto::class, [
            'token' => MapRule::from('token')->nested(ApiAccessTokenDto::class),
        ]);
        $this->assertStructuralFailure($this->structuralFactory(
            new MappingDefinition(AccessToken::class, ApiAccessTokenDto::class),
            $wrong,
        ), $wrong, WrongTypedTokenHolderSource::class);
    }

    /**
     * @param class-string $source
     * @param class-string $target
     */
    private function metadata(string $source, string $target): MappingMetadata
    {
        return $this->mappingMetadataFactory->create(new MappingDefinition($source, $target));
    }

    private function structuralFactory(CustomMappingDefinition|MappingDefinition|ProviderCustomMappingDefinition ...$definitions): MappingMetadataFactory
    {
        $mappingRegistry = new MappingRegistry($definitions);

        return new MappingMetadataFactory(new ValueTransformerRegistry(), mappingRegistry: $mappingRegistry);
    }

    private function assertStructuralFailure(MappingMetadataFactory $mappingMetadataFactory, MappingDefinition $mappingDefinition, string $expected, string $parameter = 'token'): void
    {
        try {
            $mappingMetadataFactory->create($mappingDefinition);
            self::fail('Expected structural metadata compilation to fail.');
        } catch (MappingCompilationFailed $exception) {
            self::assertStringContainsString($mappingDefinition->source(), $exception->getMessage());
            self::assertStringContainsString($mappingDefinition->target(), $exception->getMessage());
            self::assertStringContainsString('parameter $' . $parameter, $exception->getMessage());
            self::assertStringContainsString($expected, $exception->getMessage());
        }
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
