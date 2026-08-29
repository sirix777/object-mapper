<?php

declare(strict_types=1);

namespace Sirix\ObjectMapperTest\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Definition\MapRule;
use Sirix\ObjectMapper\Generator\PhpMapperGenerator;
use Sirix\ObjectMapper\Metadata\MappingMetadata;
use Sirix\ObjectMapper\Metadata\MappingMetadataFactory;
use Sirix\ObjectMapper\Metadata\NestedMappingMetadata;
use Sirix\ObjectMapper\Metadata\TargetParameter;
use Sirix\ObjectMapper\Metadata\TransformerMetadata;
use Sirix\ObjectMapper\Runtime\MappingRegistry;
use Sirix\ObjectMapper\Runtime\ValueTransformerRegistry;
use Sirix\ObjectMapperTest\Support\AccessToken;
use Sirix\ObjectMapperTest\Support\AlternativeRelease;
use Sirix\ObjectMapperTest\Support\AlternativeReleaseDto;
use Sirix\ObjectMapperTest\Support\AlternativeUuidToStringTransformer;
use Sirix\ObjectMapperTest\Support\ApiAccessTokenDto;
use Sirix\ObjectMapperTest\Support\ConventionalSource;
use Sirix\ObjectMapperTest\Support\ConventionalTarget;
use Sirix\ObjectMapperTest\Support\DateTimeToAtomTransformer;
use Sirix\ObjectMapperTest\Support\DefaultSource;
use Sirix\ObjectMapperTest\Support\DefaultTarget;
use Sirix\ObjectMapperTest\Support\ExplicitMethodSource;
use Sirix\ObjectMapperTest\Support\ExplicitMethodTarget;
use Sirix\ObjectMapperTest\Support\NullableTokenHolderDto;
use Sirix\ObjectMapperTest\Support\NullableTokenHolderSource;
use Sirix\ObjectMapperTest\Support\ProfileTarget;
use Sirix\ObjectMapperTest\Support\Release;
use Sirix\ObjectMapperTest\Support\ReleaseCollectionDto;
use Sirix\ObjectMapperTest\Support\ReleaseCollectionSource;
use Sirix\ObjectMapperTest\Support\ReleaseDto;
use Sirix\ObjectMapperTest\Support\RulePrecedenceSource;
use Sirix\ObjectMapperTest\Support\SelectorChildDto;
use Sirix\ObjectMapperTest\Support\SelectorChildHolderDto;
use Sirix\ObjectMapperTest\Support\SelectorChildHolderSource;
use Sirix\ObjectMapperTest\Support\SelectorChildSource;
use Sirix\ObjectMapperTest\Support\TokenHolderDto;
use Sirix\ObjectMapperTest\Support\TokenHolderSource;
use Sirix\ObjectMapperTest\Support\UuidToStringTransformer;

use function str_repeat;

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
        self::assertStringContainsString('$source::class !== \Sirix\ObjectMapperTest\Support\ConventionalSource::class', $phpMapperGenerator->generate($mappingMetadata, $key));
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

    public function testItGeneratesAValidatedTransformerRegistryCallAndNormalizesTransformerMetadata(): void
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
        $phpMapperGenerator = new PhpMapperGenerator();
        $code               = $phpMapperGenerator->generate($mappingMetadata, $phpMapperGenerator->cacheKey($mappingMetadata));

        self::assertStringContainsString(
            '$this->transformers->get(\Sirix\ObjectMapperTest\Support\UuidToStringTransformer::class)->transform($source->getIdentifier())',
            $code,
        );
        self::assertStringContainsString('ValueTransformerRegistryInterface $transformers', $code);

        $differentTransformer = (new MappingMetadataFactory(new ValueTransformerRegistry([
            new AlternativeUuidToStringTransformer(),
            new DateTimeToAtomTransformer(),
        ])))->create(new MappingDefinition(
            ExplicitMethodSource::class,
            ExplicitMethodTarget::class,
            [
                'id'        => MapRule::fromMethod('getIdentifier')->through(AlternativeUuidToStringTransformer::class),
                'createdAt' => MapRule::fromMethod('createdAt')->through(DateTimeToAtomTransformer::class),
                'slug'      => MapRule::fromMethod('slug'),
            ],
        ));
        self::assertNotSame($phpMapperGenerator->cacheKey($mappingMetadata), $phpMapperGenerator->cacheKey($differentTransformer));
    }

    public function testItsCacheKeyChangesWhenTheTransformMethodFileHashChanges(): void
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
        $originalParameter   = $mappingMetadata->parameters[0];
        $originalTransformer = $originalParameter->transformer;
        self::assertNotNull($originalTransformer);

        $parameters    = $mappingMetadata->parameters;
        $parameters[0] = new TargetParameter(
            $originalParameter->name,
            $originalParameter->sourceMember,
            $originalParameter->hasDefault,
            $originalParameter->type,
            $originalParameter->declaringClass,
            new TransformerMetadata(
                $originalTransformer->class,
                $originalTransformer->inputType,
                $originalTransformer->inputDeclaringClass,
                $originalTransformer->outputType,
                $originalTransformer->outputDeclaringClass,
                str_repeat('0', 64),
            ),
        );
        $changedHashMetadata = new MappingMetadata(
            $mappingMetadata->source,
            $mappingMetadata->target,
            $parameters,
            $mappingMetadata->sourceFileHash,
            $mappingMetadata->targetFileHash,
        );
        $phpMapperGenerator = new PhpMapperGenerator();

        self::assertNotSame(
            $phpMapperGenerator->cacheKey($mappingMetadata),
            $phpMapperGenerator->cacheKey($changedHashMetadata),
        );
    }

    public function testItGeneratesFixedNestedAndCollectionDispatchCode(): void
    {
        $token   = new MappingDefinition(AccessToken::class, ApiAccessTokenDto::class);
        $release = new MappingDefinition(Release::class, ReleaseDto::class);
        $parent  = new MappingDefinition(TokenHolderSource::class, TokenHolderDto::class, [
            'token' => MapRule::from('token')->nested(ApiAccessTokenDto::class),
        ]);
        $collectionParent = new MappingDefinition(ReleaseCollectionSource::class, ReleaseCollectionDto::class, [
            'releases' => MapRule::from('releases')->collection(Release::class, ReleaseDto::class),
        ]);
        $mappingMetadataFactory   = new MappingMetadataFactory(mappingRegistry: new MappingRegistry([$token, $release, $parent, $collectionParent]));
        $phpMapperGenerator       = new PhpMapperGenerator();

        $nestedCode     = $phpMapperGenerator->generate($mappingMetadataFactory->create($parent), $phpMapperGenerator->cacheKey($mappingMetadataFactory->create($parent)));
        $collectionCode = $phpMapperGenerator->generate($mappingMetadataFactory->create($collectionParent), $phpMapperGenerator->cacheKey($mappingMetadataFactory->create($collectionParent)));

        self::assertStringContainsString('NestedMappingRuntimeInterface $nestedMappings', $nestedCode);
        self::assertStringContainsString('mapNested($source->token, \Sirix\ObjectMapperTest\Support\AccessToken::class, \Sirix\ObjectMapperTest\Support\ApiAccessTokenDto::class)', $nestedCode);
        self::assertStringContainsString('private function mapCollectionForParameter0(array $values): array', $collectionCode);
        self::assertStringContainsString('$mapped[] = $this->nestedMappings->mapNested($element, \Sirix\ObjectMapperTest\Support\Release::class, \Sirix\ObjectMapperTest\Support\ReleaseDto::class);', $collectionCode);
        self::assertStringContainsString('$element::class !== \Sirix\ObjectMapperTest\Support\Release::class', $collectionCode);
        self::assertStringContainsString('collectionElementTypeFailure(', $collectionCode);
    }

    public function testItGeneratesExplicitNullableNestedGuardAndChangesKeysForStructuralOperations(): void
    {
        $token    = new MappingDefinition(AccessToken::class, ApiAccessTokenDto::class);
        $required = new MappingDefinition(TokenHolderSource::class, TokenHolderDto::class, [
            'token' => MapRule::from('token')->nested(ApiAccessTokenDto::class),
        ]);
        $nullable = new MappingDefinition(NullableTokenHolderSource::class, NullableTokenHolderDto::class, [
            'token' => MapRule::from('token')->nested(ApiAccessTokenDto::class),
        ]);
        $mappingMetadataFactory   = new MappingMetadataFactory(mappingRegistry: new MappingRegistry([$token, $required, $nullable]));
        $phpMapperGenerator       = new PhpMapperGenerator();
        $mappingMetadata          = $mappingMetadataFactory->create($nullable);

        $generated = $phpMapperGenerator->generate($mappingMetadata, $phpMapperGenerator->cacheKey($mappingMetadata));
        self::assertStringContainsString('$nestedValue0 = $source->token;', $generated);
        self::assertStringContainsString('null === $nestedValue0 ? null : $this->nestedMappings->mapNested($nestedValue0', $generated);
        self::assertNotSame($phpMapperGenerator->cacheKey($mappingMetadataFactory->create($required)), $phpMapperGenerator->cacheKey($mappingMetadata));
    }

    public function testItsCacheKeyChangesForCollectionElementsChildRulesAndChildModelFingerprints(): void
    {
        $release            = new MappingDefinition(Release::class, ReleaseDto::class);
        $alternativeRelease = new MappingDefinition(AlternativeRelease::class, AlternativeReleaseDto::class);
        $collection         = new MappingDefinition(ReleaseCollectionSource::class, ReleaseCollectionDto::class, [
            'releases' => MapRule::from('releases')->collection(Release::class, ReleaseDto::class),
        ]);
        $alternativeCollection = new MappingDefinition(ReleaseCollectionSource::class, ReleaseCollectionDto::class, [
            'releases' => MapRule::from('releases')->collection(AlternativeRelease::class, AlternativeReleaseDto::class),
        ]);
        $propertyChild = new MappingDefinition(SelectorChildSource::class, SelectorChildDto::class);
        $getterChild   = new MappingDefinition(SelectorChildSource::class, SelectorChildDto::class, [
            'value' => MapRule::fromGetter('getValue'),
        ], ['value']);
        $parent = new MappingDefinition(SelectorChildHolderSource::class, SelectorChildHolderDto::class, [
            'child' => MapRule::from('child')->nested(SelectorChildDto::class),
        ]);
        $phpMapperGenerator = new PhpMapperGenerator();

        $collectionMetadata = (new MappingMetadataFactory(mappingRegistry: new MappingRegistry([$release, $collection])))
            ->create($collection)
        ;
        $alternativeCollectionMetadata = (new MappingMetadataFactory(mappingRegistry: new MappingRegistry([$alternativeRelease, $alternativeCollection])))
            ->create($alternativeCollection)
        ;
        self::assertNotSame($phpMapperGenerator->cacheKey($collectionMetadata), $phpMapperGenerator->cacheKey($alternativeCollectionMetadata));

        $propertyMetadata = (new MappingMetadataFactory(mappingRegistry: new MappingRegistry([$propertyChild, $parent])))
            ->create($parent)
        ;
        $getterMetadata = (new MappingMetadataFactory(mappingRegistry: new MappingRegistry([$getterChild, $parent])))
            ->create($parent)
        ;
        self::assertNotSame($phpMapperGenerator->cacheKey($propertyMetadata), $phpMapperGenerator->cacheKey($getterMetadata));

        $parameter = $propertyMetadata->parameters[0];
        self::assertNotNull($parameter->nestedMapping);
        $mappingMetadata = new MappingMetadata(
            $propertyMetadata->source,
            $propertyMetadata->target,
            [new TargetParameter(
                $parameter->name,
                $parameter->sourceMember,
                $parameter->hasDefault,
                $parameter->type,
                $parameter->declaringClass,
                $parameter->transformer,
                new NestedMappingMetadata(
                    $parameter->nestedMapping->operation,
                    $parameter->nestedMapping->source,
                    $parameter->nestedMapping->target,
                    $parameter->nestedMapping->nullable,
                    str_repeat('1', 64),
                    $parameter->nestedMapping->elementSource,
                    $parameter->nestedMapping->elementTarget,
                ),
            )],
            $propertyMetadata->sourceFileHash,
            $propertyMetadata->targetFileHash,
        );
        self::assertNotSame($phpMapperGenerator->cacheKey($propertyMetadata), $phpMapperGenerator->cacheKey($mappingMetadata));
    }
}
