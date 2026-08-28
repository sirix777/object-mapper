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
use Sirix\ObjectMapper\Metadata\TargetParameter;
use Sirix\ObjectMapper\Metadata\TransformerMetadata;
use Sirix\ObjectMapper\Runtime\ValueTransformerRegistry;
use Sirix\ObjectMapperTest\Support\AlternativeUuidToStringTransformer;
use Sirix\ObjectMapperTest\Support\ConventionalSource;
use Sirix\ObjectMapperTest\Support\ConventionalTarget;
use Sirix\ObjectMapperTest\Support\DateTimeToAtomTransformer;
use Sirix\ObjectMapperTest\Support\DefaultSource;
use Sirix\ObjectMapperTest\Support\DefaultTarget;
use Sirix\ObjectMapperTest\Support\ExplicitMethodSource;
use Sirix\ObjectMapperTest\Support\ExplicitMethodTarget;
use Sirix\ObjectMapperTest\Support\ProfileTarget;
use Sirix\ObjectMapperTest\Support\RulePrecedenceSource;
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
}
