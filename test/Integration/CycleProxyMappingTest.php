<?php

declare(strict_types=1);

namespace Sirix\ObjectMapperTest\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Sirix\ObjectMapper\Contract\CustomObjectMapperInterface;
use Sirix\ObjectMapper\Definition\CustomMappingDefinition;
use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Definition\MapRule;
use Sirix\ObjectMapper\Definition\SourceMatchMode;
use Sirix\ObjectMapper\Exception\MappingCompilationFailed;
use Sirix\ObjectMapper\Exception\MappingExecutionFailed;
use Sirix\ObjectMapper\Exception\MappingNotRegistered;
use Sirix\ObjectMapper\Metadata\MappingMetadataFactory;
use Sirix\ObjectMapper\Runtime\MappingRegistry;
use Sirix\ObjectMapper\Runtime\ObjectMapper;
use Sirix\ObjectMapperTest\Support\AccessToken;
use Sirix\ObjectMapperTest\Support\ApiAccessTokenDto;
use Sirix\ObjectMapperTest\Support\CycleProxyCollectionHolder;
use Sirix\ObjectMapperTest\Support\CycleProxyCollectionHolderDto;
use Sirix\ObjectMapperTest\Support\CycleProxyEntity;
use Sirix\ObjectMapperTest\Support\CycleProxyEntityDto;
use Sirix\ObjectMapperTest\Support\CycleProxyRootWithChild;
use Sirix\ObjectMapperTest\Support\CycleProxyRootWithChildDto;
use Sirix\ObjectMapperTest\Support\DirectCycleProxy;
use Sirix\ObjectMapperTest\Support\DirectCycleProxyRootWithChild;
use Sirix\ObjectMapperTest\Support\IndirectCycleDtoA;
use Sirix\ObjectMapperTest\Support\IndirectCycleDtoB;
use Sirix\ObjectMapperTest\Support\IndirectCycleDtoC;
use Sirix\ObjectMapperTest\Support\IndirectCycleProxy;
use Sirix\ObjectMapperTest\Support\IndirectCycleSourceA;
use Sirix\ObjectMapperTest\Support\IndirectCycleSourceB;
use Sirix\ObjectMapperTest\Support\IndirectCycleSourceC;
use Sirix\ObjectMapperTest\Support\NormalCycleEntitySubclass;
use Sirix\ObjectMapperTest\Support\NullableCycleProxyHolder;
use Sirix\ObjectMapperTest\Support\NullableCycleProxyHolderDto;
use Sirix\ObjectMapperTest\Support\Release;
use Sirix\ObjectMapperTest\Support\ReleaseCollectionDto;
use Sirix\ObjectMapperTest\Support\ReleaseCollectionSource;
use Sirix\ObjectMapperTest\Support\ReleaseDto;
use Sirix\ObjectMapperTest\Support\SelfCycleDto;
use Sirix\ObjectMapperTest\Support\SelfCycleSource;
use Sirix\ObjectMapperTest\Support\WrongParentCycleProxy;

use function count;
use function hash;
use function implode;
use function str_repeat;
use function strlen;
use function substr;
use function substr_count;

#[CoversClass(ObjectMapper::class)]
final class CycleProxyMappingTest extends ObjectMapperIntegrationTestCase
{
    public function testItMapsOnlyDirectCycleProxiesForTheExplicitOptIn(): void
    {
        $mappingDefinition = new MappingDefinition(
            CycleProxyEntity::class,
            CycleProxyEntityDto::class,
            sourceMatch: SourceMatchMode::CycleProxy,
        );
        $mapper = $this->mapper(true, $mappingDefinition);

        self::assertSame(1, $mapper->map(new CycleProxyEntity(1), CycleProxyEntityDto::class)->id);
        self::assertSame(2, $mapper->map(new DirectCycleProxy(2), CycleProxyEntityDto::class)->id);
    }

    public function testItRejectsCycleProxiesUnlessTheDefinitionExplicitlyOptsIn(): void
    {
        $mapper = $this->mapper(true, new MappingDefinition(CycleProxyEntity::class, CycleProxyEntityDto::class));

        $this->expectException(MappingNotRegistered::class);
        $mapper->map(new DirectCycleProxy(1), CycleProxyEntityDto::class);
    }

    public function testItRejectsNormalAndNonDirectCycleProxySubclasses(): void
    {
        $mapper = $this->mapper(true, new MappingDefinition(
            CycleProxyEntity::class,
            CycleProxyEntityDto::class,
            sourceMatch: SourceMatchMode::CycleProxy,
        ));

        foreach ([new NormalCycleEntitySubclass(1), new IndirectCycleProxy(2), new WrongParentCycleProxy(3)] as $value) {
            try {
                $mapper->map($value, CycleProxyEntityDto::class);
                self::fail('Expected source matching to reject the value.');
            } catch (MappingNotRegistered $mappingNotRegistered) {
                self::assertInstanceOf(MappingNotRegistered::class, $mappingNotRegistered);
            }
        }
    }

    public function testItPrioritizesAnExplicitDirectProxyRegistration(): void
    {
        $mapper = $this->mapper(true,
            new CustomMappingDefinition(
                CycleProxyEntity::class,
                CycleProxyEntityDto::class,
                new class implements CustomObjectMapperInterface {
                    public function map(object $source): object
                    {
                        return new CycleProxyEntityDto(101);
                    }
                },
                SourceMatchMode::CycleProxy,
            ),
            new MappingDefinition(DirectCycleProxy::class, CycleProxyEntityDto::class),
        );

        self::assertSame(1, $mapper->map(new DirectCycleProxy(1), CycleProxyEntityDto::class)->id);
    }

    public function testItRejectsAnExactChildUnderAnOptInProxyRoot(): void
    {
        $child = new MappingDefinition(CycleProxyEntity::class, CycleProxyEntityDto::class);
        $root  = new MappingDefinition(
            CycleProxyRootWithChild::class,
            CycleProxyRootWithChildDto::class,
            [
                'child' => MapRule::from('child')->nested(CycleProxyEntityDto::class),
            ],
            sourceMatch: SourceMatchMode::CycleProxy,
        );
        $mapper = $this->mapper(true, $child, $root);

        $this->expectException(MappingExecutionFailed::class);
        $mapper->map(new DirectCycleProxyRootWithChild(new DirectCycleProxy(1)), CycleProxyRootWithChildDto::class);
    }

    public function testItRejectsANonNullProxyAtAnExactNullableNestedBoundary(): void
    {
        $child  = new MappingDefinition(CycleProxyEntity::class, CycleProxyEntityDto::class);
        $parent = new MappingDefinition(NullableCycleProxyHolder::class, NullableCycleProxyHolderDto::class, [
            'child' => MapRule::from('child')->nested(CycleProxyEntityDto::class),
        ]);
        $mapper = $this->mapper(true, $child, $parent);

        $this->expectException(MappingExecutionFailed::class);
        $mapper->map(new NullableCycleProxyHolder(new DirectCycleProxy(1)), NullableCycleProxyHolderDto::class);
    }

    public function testItRetainsSafeCollectionDiagnosticsForARejectedProxyModeValue(): void
    {
        $child = new MappingDefinition(CycleProxyEntity::class, CycleProxyEntityDto::class, sourceMatch: SourceMatchMode::CycleProxy);
        $root  = new MappingDefinition(CycleProxyCollectionHolder::class, CycleProxyCollectionHolderDto::class, [
            'children' => MapRule::from('children')->collection(CycleProxyEntity::class, CycleProxyEntityDto::class),
        ]);
        $mapper       = $this->mapper(true, $child, $root);
        $createSource = static fn (mixed $children): CycleProxyCollectionHolder => new CycleProxyCollectionHolder($children);

        foreach ([new NormalCycleEntitySubclass(1), new WrongParentCycleProxy(2), new IndirectCycleProxy(3)] as $value) {
            try {
                $mapper->map($createSource([
                    'unsafe-key' => $value,
                ]), CycleProxyCollectionHolderDto::class);
                self::fail('Expected rejected collection element.');
            } catch (MappingExecutionFailed $mappingExecutionFailed) {
                self::assertStringContainsString('parameter "children"', $mappingExecutionFailed->getMessage());
                self::assertStringContainsString($value::class, $mappingExecutionFailed->getMessage());
                self::assertStringContainsString('string key sha256:', $mappingExecutionFailed->getMessage());
                self::assertStringNotContainsString('unsafe-key', $mappingExecutionFailed->getMessage());
            }
        }
    }

    public function testItReportsSafeCollectionElementAndCycleFailures(): void
    {
        $mapper = $this->mapper(
            true,
            new MappingDefinition(Release::class, ReleaseDto::class),
            new MappingDefinition(ReleaseCollectionSource::class, ReleaseCollectionDto::class, [
                'releases' => MapRule::from('releases')->collection(Release::class, ReleaseDto::class),
            ]),
        );

        $sensitiveKey = "sensitive-key\n" . str_repeat('very-long-key-', 64);
        $createSource = (static fn (mixed $values): ReleaseCollectionSource => new ReleaseCollectionSource($values));

        try {
            $mapper->map($createSource([
                $sensitiveKey => new AccessToken('secret'),
            ]), ReleaseCollectionDto::class);
            self::fail('Expected an invalid collection element to fail.');
        } catch (MappingExecutionFailed $exception) {
            self::assertStringContainsString('parameter "releases"', $exception->getMessage());
            self::assertStringContainsString('string key sha256:' . substr(hash('sha256', $sensitiveKey), 0, 16), $exception->getMessage());
            self::assertStringContainsString('length ' . strlen($sensitiveKey), $exception->getMessage());
            self::assertStringContainsString(AccessToken::class, $exception->getMessage());
            self::assertStringNotContainsString($sensitiveKey, $exception->getMessage());
            self::assertStringNotContainsString('sensitive-key', $exception->getMessage());
            self::assertStringNotContainsString('secret', $exception->getMessage());
            self::assertStringNotContainsString("\n", $exception->getMessage());
        }

        try {
            $mapper->map($createSource([
                7 => new AccessToken('secret'),
            ]), ReleaseCollectionDto::class);
            self::fail('Expected an invalid collection element to fail.');
        } catch (MappingExecutionFailed $exception) {
            self::assertStringContainsString('integer key 7', $exception->getMessage());
            self::assertStringContainsString(AccessToken::class, $exception->getMessage());
        }

        try {
            $mapper->map($createSource([
                -1 => new AccessToken('secret'),
            ]), ReleaseCollectionDto::class);
            self::fail('Expected an invalid collection element to fail.');
        } catch (MappingExecutionFailed $exception) {
            self::assertStringContainsString('integer key -1', $exception->getMessage());
        }

        $self = new MappingDefinition(SelfCycleSource::class, SelfCycleDto::class, [
            'child' => MapRule::from('child')->nested(SelfCycleDto::class),
        ]);
        $indirectA = new MappingDefinition(IndirectCycleSourceA::class, IndirectCycleDtoA::class, [
            'child' => MapRule::from('child')->nested(IndirectCycleDtoB::class),
        ]);
        $indirectB = new MappingDefinition(IndirectCycleSourceB::class, IndirectCycleDtoB::class, [
            'child' => MapRule::from('child')->nested(IndirectCycleDtoC::class),
        ]);
        $indirectC = new MappingDefinition(IndirectCycleSourceC::class, IndirectCycleDtoC::class, [
            'child' => MapRule::from('child')->nested(IndirectCycleDtoA::class),
        ]);

        foreach ([[$self], [$indirectA, $indirectB, $indirectC]] as $definitions) {
            try {
                $this->mapper(false, ...$definitions)->warmup();
                self::fail('Expected cycle detection during warmup.');
            } catch (MappingCompilationFailed $exception) {
                self::assertStringContainsString('cycle', $exception->getMessage());

                if (3 === count($definitions)) {
                    $cycle = implode(' -> ', [
                        $indirectA->key(),
                        $indirectB->key(),
                        $indirectC->key(),
                        $indirectA->key(),
                    ]);

                    $rotatedCycles = [
                        $cycle,
                        implode(' -> ', [$indirectB->key(), $indirectC->key(), $indirectA->key(), $indirectB->key()]),
                        implode(' -> ', [$indirectC->key(), $indirectA->key(), $indirectB->key(), $indirectC->key()]),
                    ];

                    self::assertSame(
                        1,
                        substr_count($exception->getMessage(), $rotatedCycles[0])
                        + substr_count($exception->getMessage(), $rotatedCycles[1])
                        + substr_count($exception->getMessage(), $rotatedCycles[2]),
                    );
                }
            }
        }
    }

    public function testItRejectsAWrongFingerprintTraversalPairBeforeACycleCanRecurse(): void
    {
        $rootMappingDefinition = new MappingDefinition(IndirectCycleSourceA::class, IndirectCycleDtoA::class, [
            'child' => MapRule::from('child')->nested(IndirectCycleDtoB::class),
        ]);
        $childMappingDefinition = new MappingDefinition(IndirectCycleSourceB::class, IndirectCycleDtoB::class, [
            'child' => MapRule::from('child')->nested(IndirectCycleDtoC::class),
        ]);
        $leafMappingDefinition = new MappingDefinition(IndirectCycleSourceC::class, IndirectCycleDtoC::class, [
            'child' => MapRule::from('child')->nested(IndirectCycleDtoA::class),
        ]);
        $wrongThenCorrectDependencyRegistry = new WrongThenCorrectDependencyRegistry(
            new MappingRegistry([$rootMappingDefinition, $childMappingDefinition, $leafMappingDefinition]),
            IndirectCycleSourceA::class,
            IndirectCycleDtoA::class,
            new MappingDefinition(AccessToken::class, ApiAccessTokenDto::class),
        );

        try {
            (new MappingMetadataFactory(mappingRegistry: $wrongThenCorrectDependencyRegistry))->create($rootMappingDefinition);
            self::fail('Expected the wrong fingerprint traversal pair to fail.');
        } catch (MappingCompilationFailed $exception) {
            self::assertStringContainsString('resolved the wrong mapping pair', $exception->getMessage());
            self::assertStringNotContainsString('maximum function nesting', $exception->getMessage());
        }
    }
}
