<?php

declare(strict_types=1);

namespace Sirix\ObjectMapperTest\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use RuntimeException;
use Sirix\ObjectMapper\Definition\CustomMappingDefinition;
use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Definition\MapRule;
use Sirix\ObjectMapper\Definition\SourceMatchMode;
use Sirix\ObjectMapper\Exception\MappingExecutionFailed;
use Sirix\ObjectMapper\Exception\MappingFailureReason;
use Sirix\ObjectMapper\Exception\MappingNotRegistered;
use Sirix\ObjectMapper\Generator\MapperCache;
use Sirix\ObjectMapper\Generator\PhpMapperGenerator;
use Sirix\ObjectMapper\Metadata\MappingMetadata;
use Sirix\ObjectMapper\Metadata\MappingMetadataFactory;
use Sirix\ObjectMapper\Metadata\NestedMappingMetadata;
use Sirix\ObjectMapper\Runtime\GeneratedMappingExecutionFailed;
use Sirix\ObjectMapper\Runtime\MappingRegistry;
use Sirix\ObjectMapper\Runtime\ObjectMapper;
use Sirix\ObjectMapper\Runtime\ValueTransformerRegistry;
use Sirix\ObjectMapperTest\Support\AccessToken;
use Sirix\ObjectMapperTest\Support\ApiAccessTokenDto;
use Sirix\ObjectMapperTest\Support\CallbackRelease;
use Sirix\ObjectMapperTest\Support\CollectionExecutionTrace;
use Sirix\ObjectMapperTest\Support\ConstructorOrderSource;
use Sirix\ObjectMapperTest\Support\ConstructorOrderTarget;
use Sirix\ObjectMapperTest\Support\CycleProxyCollectionHolder;
use Sirix\ObjectMapperTest\Support\CycleProxyCollectionHolderDto;
use Sirix\ObjectMapperTest\Support\CycleProxyEntity;
use Sirix\ObjectMapperTest\Support\CycleProxyEntityDto;
use Sirix\ObjectMapperTest\Support\CycleProxyHolder;
use Sirix\ObjectMapperTest\Support\CycleProxyHolderDto;
use Sirix\ObjectMapperTest\Support\DirectCycleProxy;
use Sirix\ObjectMapperTest\Support\NameTarget;
use Sirix\ObjectMapperTest\Support\NormalCycleEntitySubclass;
use Sirix\ObjectMapperTest\Support\NullableReleaseCollectionDto;
use Sirix\ObjectMapperTest\Support\NullableReleaseCollectionSource;
use Sirix\ObjectMapperTest\Support\NullableTokenHolderDto;
use Sirix\ObjectMapperTest\Support\NullableTokenHolderSource;
use Sirix\ObjectMapperTest\Support\ObservedRelease;
use Sirix\ObjectMapperTest\Support\ObservedReleaseCollectionsDto;
use Sirix\ObjectMapperTest\Support\ObservedReleaseCollectionsSource;
use Sirix\ObjectMapperTest\Support\ObservedReleaseTransformer;
use Sirix\ObjectMapperTest\Support\OrderFailingTransformer;
use Sirix\ObjectMapperTest\Support\ProfileSource;
use Sirix\ObjectMapperTest\Support\ProfileTarget;
use Sirix\ObjectMapperTest\Support\Release;
use Sirix\ObjectMapperTest\Support\ReleaseCollectionDto;
use Sirix\ObjectMapperTest\Support\ReleaseCollectionSource;
use Sirix\ObjectMapperTest\Support\ReleaseDto;
use Sirix\ObjectMapperTest\Support\ThreeLevelLeafDto;
use Sirix\ObjectMapperTest\Support\ThreeLevelLeafSource;
use Sirix\ObjectMapperTest\Support\ThreeLevelMiddleDto;
use Sirix\ObjectMapperTest\Support\ThreeLevelMiddleSource;
use Sirix\ObjectMapperTest\Support\ThreeLevelRootDto;
use Sirix\ObjectMapperTest\Support\TokenHolderDto;
use Sirix\ObjectMapperTest\Support\TokenHolderSource;
use stdClass;

use WeakReference;

use function array_keys;
use function array_map;
use function class_exists;
use function function_exists;
use function hash;
use function in_array;
use function substr;
use function xdebug_info;

#[CoversClass(ObjectMapper::class)]
final class StructuralMappingTest extends ObjectMapperIntegrationTestCase
{
    public function testItEvaluatesArgumentsInConstructorOrder(): void
    {
        foreach ([[new Release('1'), [new Release('2')]], [null, []]] as [$child, $items]) {
            ConstructorOrderTarget::$constructions = 0;
            $source                                = new ConstructorOrderSource($child, $items);

            $target = $this->orderMapper()->map($source, ConstructorOrderTarget::class);

            self::assertSame(['first', 'child', 'items', 'last'], $source->events());
            self::assertSame(1, ConstructorOrderTarget::$constructions);
            self::assertSame(1, $target->first);
            self::assertSame('last', $target->last);
        }
    }

    public function testItDoesNotReadLaterNullableMembersAfterAnEarlierFailure(): void
    {
        ConstructorOrderTarget::$constructions = 0;
        $throwingSource                        = new ConstructorOrderSource(new Release('1'), [new Release('2')], throwFirst: true);

        try {
            $this->orderMapper()->map($throwingSource, ConstructorOrderTarget::class);
            self::fail('Expected the first getter to fail.');
        } catch (MappingExecutionFailed) {
        }

        self::assertSame(['first'], $throwingSource->events());
        self::assertSame(0, ConstructorOrderTarget::$constructions);

        ConstructorOrderTarget::$constructions = 0;
        $transformerSource                     = new ConstructorOrderSource(new Release('1'), [new Release('2')]);
        $objectMapper                          = $this->mapperWithTransformers(
            true,
            new ValueTransformerRegistry([new OrderFailingTransformer()]),
            new MappingDefinition(Release::class, ReleaseDto::class),
            new MappingDefinition(ConstructorOrderSource::class, ConstructorOrderTarget::class, [
                'first' => MapRule::fromGetter('getFirst')->through(OrderFailingTransformer::class),
                'child' => MapRule::fromGetter('getChild')->nested(ReleaseDto::class),
                'items' => MapRule::fromGetter('getItems')->collection(Release::class, ReleaseDto::class),
            ]),
        );

        try {
            $objectMapper->map($transformerSource, ConstructorOrderTarget::class);
            self::fail('Expected the transformer to fail.');
        } catch (MappingExecutionFailed) {
        }

        self::assertSame(['first'], $transformerSource->events());
        self::assertSame(0, ConstructorOrderTarget::$constructions);
    }

    public function testItDoesNotProbeAParentPairForAnOrdinarySubclass(): void
    {
        $mappingDefinition                = new MappingDefinition(CycleProxyEntity::class, CycleProxyEntityDto::class);
        $countingMappingRegistry          = new CountingMappingRegistry(new MappingRegistry([$mappingDefinition]));
        $valueTransformerRegistry         = new ValueTransformerRegistry();
        $objectMapper                     = new ObjectMapper(
            $countingMappingRegistry,
            new MapperCache(
                new MappingMetadataFactory($valueTransformerRegistry, mappingRegistry: $countingMappingRegistry),
                new PhpMapperGenerator(),
                $this->cacheDirectory,
                $valueTransformerRegistry,
                true,
                $countingMappingRegistry,
            ),
        );

        try {
            $objectMapper->map(new NormalCycleEntitySubclass(1), CycleProxyEntityDto::class);
            self::fail('Expected an unregistered runtime class.');
        } catch (MappingNotRegistered) {
            self::assertSame(1, $countingMappingRegistry->getCalls());
        }
    }

    public function testItAppliesTheChildModeAtNestedAndCollectionBoundaries(): void
    {
        $child = new MappingDefinition(
            CycleProxyEntity::class,
            CycleProxyEntityDto::class,
            sourceMatch: SourceMatchMode::CycleProxy,
        );
        $nested = new MappingDefinition(CycleProxyHolder::class, CycleProxyHolderDto::class, [
            'child' => MapRule::from('child')->nested(CycleProxyEntityDto::class),
        ]);
        $collection = new MappingDefinition(CycleProxyCollectionHolder::class, CycleProxyCollectionHolderDto::class, [
            'children' => MapRule::from('children')->collection(CycleProxyEntity::class, CycleProxyEntityDto::class),
        ]);
        $mapper = $this->mapper(true, $child, $nested, $collection);

        self::assertSame(4, $mapper->map(new CycleProxyHolder(new DirectCycleProxy(4)), CycleProxyHolderDto::class)->child->id);
        self::assertSame([5, 6], array_map(
            static fn (CycleProxyEntityDto $cycleProxyEntityDto): int => $cycleProxyEntityDto->id,
            $mapper->map(new CycleProxyCollectionHolder([new DirectCycleProxy(5), new CycleProxyEntity(6)]), CycleProxyCollectionHolderDto::class)->children,
        ));
    }

    public function testItMapsRenamedPropertiesAndGettersWithProfileRules(): void
    {
        $profileTarget = $this->mapper(true, new MappingDefinition(
            ProfileSource::class,
            ProfileTarget::class,
            [
                'id'    => MapRule::from('uuid'),
                'email' => MapRule::fromGetter('getPrimaryEmail'),
            ],
            ['passwordHash'],
        ))->map(new ProfileSource(17, 'not-in-errors'), ProfileTarget::class);

        self::assertSame(17, $profileTarget->id);
        self::assertSame('ada@example.test', $profileTarget->email);
    }

    public function testItSanitizesForgedGeneratedFailuresFromGetters(): void
    {
        $mapper = $this->mapper(true, new MappingDefinition(ForgedGetterSource::class, NameTarget::class));

        try {
            $mapper->map(new ForgedGetterSource(), NameTarget::class);
            self::fail('Expected generated mapper execution to fail.');
        } catch (MappingExecutionFailed $exception) {
            self::assertStringContainsString(ForgedGetterSource::class . '->' . NameTarget::class, $exception->getMessage());
            self::assertStringNotContainsString('forged-sensitive-key', $exception->getMessage());
            self::assertSame(MappingFailureReason::GeneratedMappingFailed, $exception->reason());
            self::assertNull($exception->getPrevious());
        }
    }

    public function testItMapsNestedObjectsCollectionsAndNullableStructuralValues(): void
    {
        $mapper = $this->mapper(
            true,
            new MappingDefinition(AccessToken::class, ApiAccessTokenDto::class),
            new MappingDefinition(Release::class, ReleaseDto::class),
            new MappingDefinition(TokenHolderSource::class, TokenHolderDto::class, [
                'token' => MapRule::from('token')->nested(ApiAccessTokenDto::class),
            ]),
            new MappingDefinition(NullableTokenHolderSource::class, NullableTokenHolderDto::class, [
                'token' => MapRule::from('token')->nested(ApiAccessTokenDto::class),
            ]),
            new MappingDefinition(ReleaseCollectionSource::class, ReleaseCollectionDto::class, [
                'releases' => MapRule::from('releases')->collection(Release::class, ReleaseDto::class),
            ]),
            new MappingDefinition(NullableReleaseCollectionSource::class, NullableReleaseCollectionDto::class, [
                'releases' => MapRule::from('releases')->collection(Release::class, ReleaseDto::class),
            ]),
        );

        $tokenHolderDto       = $mapper->map(new TokenHolderSource(new AccessToken('trusted')), TokenHolderDto::class);
        $releaseCollectionDto = $mapper->map(new ReleaseCollectionSource([
            'first' => new Release('1.0'),
            9       => new Release('2.0'),
        ]), ReleaseCollectionDto::class);

        self::assertSame('trusted', $tokenHolderDto->token->value);
        self::assertSame(['1.0', '2.0'], array_map(static fn (ReleaseDto $releaseDto): string => $releaseDto->version, $releaseCollectionDto->releases));
        self::assertSame([0, 1], array_keys($releaseCollectionDto->releases));
        self::assertNull($mapper->map(new NullableTokenHolderSource(null), NullableTokenHolderDto::class)->token);
        self::assertNull($mapper->map(new NullableReleaseCollectionSource(null), NullableReleaseCollectionDto::class)->releases);
    }

    public function testCollectionValidationIsInterleavedWithGettersAndTransformers(): void
    {
        foreach ([0, 1, 2] as $invalidPosition) {
            $trace  = new CollectionExecutionTrace();
            $mapper = $this->mapperWithTransformers(
                true,
                new ValueTransformerRegistry([new ObservedReleaseTransformer($trace)]),
                new MappingDefinition(ObservedRelease::class, ReleaseDto::class, [
                    'version' => MapRule::fromGetter('getVersion')->through(ObservedReleaseTransformer::class),
                ]),
                new MappingDefinition(ObservedReleaseCollectionsSource::class, ObservedReleaseCollectionsDto::class, [
                    'first'  => MapRule::fromGetter('getFirst')->collection(ObservedRelease::class, ReleaseDto::class),
                    'second' => MapRule::fromGetter('getSecond')->collection(ObservedRelease::class, ReleaseDto::class),
                ]),
            );
            $items          = [];
            $expectedEvents = ['first'];
            foreach ([0, 1, 2] as $position) {
                $items[$position + 10] = $position === $invalidPosition ? null : new ObservedRelease((string) $position, $trace);
                if ($position < $invalidPosition) {
                    $expectedEvents[] = 'get:' . $position;
                    $expectedEvents[] = 'transform:' . $position;
                }
            }

            try {
                $mapper->map(new ObservedReleaseCollectionsSource($items, [], $trace), ObservedReleaseCollectionsDto::class);
                self::fail('Expected an invalid element to stop collection execution.');
            } catch (MappingExecutionFailed $exception) {
                self::assertStringContainsString('parameter "first"', $exception->getMessage());
                self::assertStringContainsString('integer key ' . ($invalidPosition + 10), $exception->getMessage());
                self::assertSame($expectedEvents, $trace->events);
            }
        }
    }

    public function testCollectionsSharingAChildPairKeepParameterOrderAndNullSemantics(): void
    {
        $collectionExecutionTrace  = new CollectionExecutionTrace();
        $objectMapper              = $this->mapperWithTransformers(
            true,
            new ValueTransformerRegistry([new ObservedReleaseTransformer($collectionExecutionTrace)]),
            new MappingDefinition(ObservedRelease::class, ReleaseDto::class, [
                'version' => MapRule::fromGetter('getVersion')->through(ObservedReleaseTransformer::class),
            ]),
            new MappingDefinition(ObservedReleaseCollectionsSource::class, ObservedReleaseCollectionsDto::class, [
                'first'  => MapRule::fromGetter('getFirst')->collection(ObservedRelease::class, ReleaseDto::class),
                'second' => MapRule::fromGetter('getSecond')->collection(ObservedRelease::class, ReleaseDto::class),
            ]),
        );

        $result = $objectMapper->map(new ObservedReleaseCollectionsSource(null, [], $collectionExecutionTrace), ObservedReleaseCollectionsDto::class);
        self::assertNull($result->first);
        self::assertSame([], $result->second);
        self::assertSame(['first', 'second'], $collectionExecutionTrace->events);

        $collectionExecutionTrace->events = [];
        $result                           = $objectMapper->map(new ObservedReleaseCollectionsSource(
            [
                'sensitive-key' => new ObservedRelease('a', $collectionExecutionTrace),
            ],
            [
                -5 => new ObservedRelease('b', $collectionExecutionTrace),
            ],
            $collectionExecutionTrace,
        ), ObservedReleaseCollectionsDto::class);
        self::assertEquals([new ReleaseDto('a')], $result->first);
        self::assertEquals([new ReleaseDto('b')], $result->second);
        self::assertSame(['first', 'get:a', 'transform:a', 'second', 'get:b', 'transform:b'], $collectionExecutionTrace->events);

        $collectionExecutionTrace->events = [];

        try {
            $objectMapper->map(new ObservedReleaseCollectionsSource(
                [new ObservedRelease('a', $collectionExecutionTrace)],
                [
                    "sensitive-key\n" => false,
                ],
                $collectionExecutionTrace,
            ), ObservedReleaseCollectionsDto::class);
            self::fail('Expected the second parameter to fail.');
        } catch (MappingExecutionFailed $exception) {
            self::assertStringContainsString('parameter "second"', $exception->getMessage());
            self::assertStringNotContainsString('sensitive-key', $exception->getMessage());
            self::assertSame(['first', 'get:a', 'transform:a', 'second'], $collectionExecutionTrace->events);
        }
    }

    #[DataProvider('collectionCacheModes')]
    public function testCollectionHelperRejectsEmptyCallsOutsideAndInsideTheWrongFrame(bool $reusePreparedMappings): void
    {
        [$mapper, $cache] = $this->collectionCallbackRuntime(reusePreparedMappings: $reusePreparedMappings);
        $dispatch         = static function(string $parameter, string $elementSource, string $elementTarget, SourceMatchMode $sourceMatchMode) use ($cache): ?array {
            if (! class_exists($elementSource) || ! class_exists($elementTarget)) {
                throw new RuntimeException('Expected existing fixture classes.');
            }

            return $cache->mapCollection(
                [], ObservedReleaseCollectionsSource::class, ObservedReleaseCollectionsDto::class,
                $parameter, $elementSource, $elementTarget, $sourceMatchMode,
            );
        };
        $assertRejected = static function() use ($dispatch): void {
            try {
                $dispatch('first', CallbackRelease::class, ReleaseDto::class, SourceMatchMode::Exact);
                self::fail('Expected an inactive collection dispatch to fail, even with no elements.');
            } catch (MappingExecutionFailed $exception) {
                self::assertNull($exception->getPrevious());
            }
        };
        $assertRejected();

        $observedReleaseCollectionsSource = new ObservedReleaseCollectionsSource([], [], new CollectionExecutionTrace(), static function() use ($dispatch, $cache): void {
            foreach ([
                ['missing', CallbackRelease::class, ReleaseDto::class, SourceMatchMode::Exact],
                ['first', Release::class, ReleaseDto::class, SourceMatchMode::Exact],
                ['first', CallbackRelease::class, ApiAccessTokenDto::class, SourceMatchMode::Exact],
                ['first', CallbackRelease::class, ReleaseDto::class, SourceMatchMode::CycleProxy],
            ] as [$parameter, $elementSource, $elementTarget, $mode]) {
                try {
                    $dispatch($parameter, $elementSource, $elementTarget, $mode);
                    self::fail('Expected invalid declared collection identity to fail.');
                } catch (MappingExecutionFailed $exception) {
                    self::assertNull($exception->getPrevious());
                }
            }

            foreach ([
                [ReleaseCollectionSource::class, ObservedReleaseCollectionsDto::class],
                [ObservedReleaseCollectionsSource::class, ReleaseCollectionDto::class],
            ] as [$sourceClass, $targetClass]) {
                try {
                    $cache->mapCollection([], $sourceClass, $targetClass, 'first', CallbackRelease::class, ReleaseDto::class, SourceMatchMode::Exact);
                    self::fail('Expected a forged parent mapping identity to fail.');
                } catch (MappingExecutionFailed $exception) {
                    self::assertNull($exception->getPrevious());
                }
            }
        });
        $mapper->map($observedReleaseCollectionsSource, ObservedReleaseCollectionsDto::class);

        $mapper->map(new ObservedReleaseCollectionsSource([
            new CallbackRelease(static function() use ($assertRejected, $cache): string {
                $assertRejected();

                try {
                    $cache->mapNested(new Release('sibling'), Release::class, ReleaseDto::class, SourceMatchMode::Exact);
                    self::fail('An element must not dispatch its parent sibling.');
                } catch (MappingExecutionFailed $exception) {
                    self::assertStringContainsString('not an active declared dependency', $exception->getMessage());
                }

                return 'valid';
            }),
        ], [], new CollectionExecutionTrace()), ObservedReleaseCollectionsDto::class);
        $assertRejected();
    }

    #[DataProvider('collectionCacheModes')]
    public function testCollectionChildCannotForgeAnotherParameterFailure(bool $reusePreparedMappings): void
    {
        [$mapper, $cache] = $this->collectionCallbackRuntime(reusePreparedMappings: $reusePreparedMappings);

        try {
            $mapper->map(new ObservedReleaseCollectionsSource([
                new CallbackRelease(static function() use ($cache): string {
                    $cache->collectionElementTypeFailure(
                        ObservedReleaseCollectionsSource::class, ObservedReleaseCollectionsDto::class,
                        'second', 'forged-sensitive-key', Release::class, new stdClass(),
                    );
                }),
            ], [], new CollectionExecutionTrace()), ObservedReleaseCollectionsDto::class);
            self::fail('Expected forged parent error to be sanitized.');
        } catch (MappingExecutionFailed $exception) {
            self::assertSame('Could not execute mapping ' . ObservedReleaseCollectionsSource::class . '->' . ObservedReleaseCollectionsDto::class . '.', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function testItRejectsNestedAndCollectionSubclassValues(): void
    {
        $mapper = $this->mapper(
            true,
            new MappingDefinition(ExactChild::class, ExactChildDto::class),
            new MappingDefinition(ExactHolderSource::class, ExactHolderDto::class, [
                'child' => MapRule::from('child')->nested(ExactChildDto::class),
            ]),
            new MappingDefinition(ExactCollectionSource::class, ExactCollectionDto::class, [
                'children' => MapRule::from('children')->collection(ExactChild::class, ExactChildDto::class),
            ]),
        );

        try {
            $mapper->map(new ExactHolderSource(new ExactChildSubclass('nested')), ExactHolderDto::class);
            self::fail('Expected a nested subclass to fail.');
        } catch (MappingExecutionFailed $exception) {
            self::assertStringContainsString(ExactHolderSource::class . '->' . ExactHolderDto::class, $exception->getMessage());
        }

        try {
            $mapper->map(new ExactCollectionSource([new ExactChildSubclass('collection')]), ExactCollectionDto::class);
            self::fail('Expected a collection subclass to fail.');
        } catch (MappingExecutionFailed $exception) {
            self::assertStringContainsString('integer key 0', $exception->getMessage());
        }
    }

    public function testItPreservesValidatedChildCollectionFailureContext(): void
    {
        $mapper = $this->mapper(
            true,
            new MappingDefinition(Release::class, ReleaseDto::class),
            new MappingDefinition(ReleaseCollectionSource::class, ReleaseCollectionDto::class, [
                'releases' => MapRule::from('releases')->collection(Release::class, ReleaseDto::class),
            ]),
            new MappingDefinition(NestedReleaseCollectionSource::class, NestedReleaseCollectionDto::class, [
                'collection' => MapRule::from('collection')->nested(ReleaseCollectionDto::class),
            ]),
        );
        $createCollection = (static fn (mixed $releases): ReleaseCollectionSource => new ReleaseCollectionSource($releases));

        try {
            $mapper->map(
                new NestedReleaseCollectionSource($createCollection([
                    3 => new AccessToken('secret'),
                ])),
                NestedReleaseCollectionDto::class,
            );
            self::fail('Expected nested collection element validation to fail.');
        } catch (MappingExecutionFailed $exception) {
            self::assertStringContainsString(ReleaseCollectionSource::class . '->' . ReleaseCollectionDto::class, $exception->getMessage());
            self::assertStringContainsString('parameter "releases"', $exception->getMessage());
            self::assertStringContainsString('integer key 3', $exception->getMessage());
            self::assertStringContainsString(AccessToken::class, $exception->getMessage());
            self::assertStringNotContainsString('secret', $exception->getMessage());
        }
    }

    public function testItReadsNullableNestedGettersOnlyOnceAndUsesDistinctCollectionHelpers(): void
    {
        $nullableGetterHolderSource = new NullableGetterHolderSource(new ExactChild('once'));
        $mapper                     = $this->mapper(
            true,
            new MappingDefinition(ExactChild::class, ExactChildDto::class),
            new MappingDefinition(NullableGetterHolderSource::class, NullableGetterHolderDto::class, [
                'child' => MapRule::fromGetter('getChild')->nested(ExactChildDto::class),
            ]),
            new MappingDefinition(CollectionHelperCollisionSource::class, CollectionHelperCollisionDto::class, [
                'items' => MapRule::from('items')->collection(ExactChild::class, ExactChildDto::class),
                'Items' => MapRule::from('Items')->collection(ExactChild::class, ExactChildDto::class),
            ]),
        );

        $nullableGetterHolderDto = $mapper->map($nullableGetterHolderSource, NullableGetterHolderDto::class);
        self::assertNotNull($nullableGetterHolderDto->child);
        self::assertSame('once', $nullableGetterHolderDto->child->value);
        self::assertSame(1, $nullableGetterHolderSource->readCount());

        $collectionHelperCollisionDto = $mapper->map(new CollectionHelperCollisionSource([new ExactChild('a')], [new ExactChild('b')]), CollectionHelperCollisionDto::class);
        self::assertSame('a', $collectionHelperCollisionDto->items[0]->value);
        self::assertSame('b', $collectionHelperCollisionDto->Items[0]->value);
    }

    public function testItSanitizesMaliciousCollectionFailureHelperCalls(): void
    {
        $mapper = $this->mapper(
            true,
            new MappingDefinition(Release::class, ReleaseDto::class),
            new MappingDefinition(MaliciousCollectionFailureSource::class, ReleaseCollectionDto::class, [
                'releases' => MapRule::fromGetter('getReleases')->collection(Release::class, ReleaseDto::class),
            ]),
        );

        try {
            $mapper->map(new MaliciousCollectionFailureSource(), ReleaseCollectionDto::class);
            self::fail('Expected malicious helper invocation to fail.');
        } catch (MappingExecutionFailed $exception) {
            self::assertStringContainsString(MaliciousCollectionFailureSource::class . '->' . ReleaseCollectionDto::class, $exception->getMessage());
            self::assertStringNotContainsString('attacker-secret', $exception->getMessage());
            self::assertSame(MappingFailureReason::GeneratedMappingFailed, $exception->reason());
            self::assertNull($exception->getPrevious());
        }

        self::assertFalse((new ReflectionClass(GeneratedMappingExecutionFailed::class))->hasProperty('token'));
    }

    public function testItPreventsRebindingValidatedCollectionFailureContexts(): void
    {
        $definitions = [
            new MappingDefinition(Release::class, ReleaseDto::class),
            new MappingDefinition(RebindingCollectionFailureSource::class, ReleaseCollectionDto::class, [
                'releases' => MapRule::fromGetter('getReleases')->collection(Release::class, ReleaseDto::class),
            ]),
        ];
        $mappingRegistry          = new MappingRegistry($definitions);
        $valueTransformerRegistry = new ValueTransformerRegistry();
        $mapperCache              = new MapperCache(
            new MappingMetadataFactory($valueTransformerRegistry, mappingRegistry: $mappingRegistry),
            new PhpMapperGenerator(),
            $this->cacheDirectory,
            $valueTransformerRegistry,
            true,
            $mappingRegistry,
        );
        $objectMapper = new ObjectMapper($mappingRegistry, $mapperCache);

        try {
            $objectMapper->map(new RebindingCollectionFailureSource($mapperCache), ReleaseCollectionDto::class);
            self::fail('Expected rebound collection failure to fail.');
        } catch (MappingExecutionFailed $exception) {
            self::assertStringContainsString(RebindingCollectionFailureSource::class . '->' . ReleaseCollectionDto::class, $exception->getMessage());
            self::assertStringContainsString('string key sha256:' . substr(hash('sha256', "attacker-secret\n"), 0, 16), $exception->getMessage());
            self::assertStringContainsString(AccessToken::class, $exception->getMessage());
            self::assertStringNotContainsString('attacker-secret', $exception->getMessage());
            self::assertStringNotContainsString("\n", $exception->getMessage());
        }
    }

    public function testItRejectsAValidCollectionFailureFromAnIndependentMapping(): void
    {
        $definitions = [
            new MappingDefinition(Release::class, ReleaseDto::class),
            new MappingDefinition(ReleaseCollectionSource::class, ReleaseCollectionDto::class, [
                'releases' => MapRule::from('releases')->collection(Release::class, ReleaseDto::class),
            ]),
            new MappingDefinition(IndependentCollectionFailureSource::class, IndependentCollectionFailureDto::class, [
                'releases' => MapRule::fromGetter('getReleases')->collection(Release::class, ReleaseDto::class),
            ]),
        ];
        $mappingRegistry          = new MappingRegistry($definitions);
        $valueTransformerRegistry = new ValueTransformerRegistry();
        $mapperCache              = new MapperCache(
            new MappingMetadataFactory($valueTransformerRegistry, mappingRegistry: $mappingRegistry),
            new PhpMapperGenerator(),
            $this->cacheDirectory,
            $valueTransformerRegistry,
            true,
            $mappingRegistry,
        );
        $objectMapper = new ObjectMapper($mappingRegistry, $mapperCache);

        try {
            $objectMapper->map(new IndependentCollectionFailureSource($mapperCache), IndependentCollectionFailureDto::class);
            self::fail('Expected an independent collection failure to be sanitized.');
        } catch (MappingExecutionFailed $exception) {
            self::assertStringContainsString(IndependentCollectionFailureSource::class . '->' . IndependentCollectionFailureDto::class, $exception->getMessage());
            self::assertStringNotContainsString(ReleaseCollectionSource::class . '->' . ReleaseCollectionDto::class, $exception->getMessage());
            self::assertStringNotContainsString('attacker-secret', $exception->getMessage());
        }
    }

    public function testItRejectsAnUnexecutedReachableCollectionFailure(): void
    {
        $definitions = [
            new MappingDefinition(Release::class, ReleaseDto::class),
            new MappingDefinition(ReleaseCollectionSource::class, ReleaseCollectionDto::class, [
                'releases' => MapRule::from('releases')->collection(Release::class, ReleaseDto::class),
            ]),
            new MappingDefinition(ReachableSiblingFailureSource::class, ReachableSiblingFailureDto::class, [
                'sibling'  => MapRule::from('sibling')->nested(ReleaseCollectionDto::class),
                'releases' => MapRule::fromGetter('getReleases')->collection(Release::class, ReleaseDto::class),
            ]),
        ];
        $mappingRegistry          = new MappingRegistry($definitions);
        $valueTransformerRegistry = new ValueTransformerRegistry();
        $mapperCache              = new MapperCache(
            new MappingMetadataFactory($valueTransformerRegistry, mappingRegistry: $mappingRegistry),
            new PhpMapperGenerator(),
            $this->cacheDirectory,
            $valueTransformerRegistry,
            true,
            $mappingRegistry,
        );
        $objectMapper = new ObjectMapper($mappingRegistry, $mapperCache);

        try {
            $objectMapper->map(new ReachableSiblingFailureSource($mapperCache), ReachableSiblingFailureDto::class);
            self::fail('Expected an unexecuted sibling failure to be sanitized.');
        } catch (MappingExecutionFailed $exception) {
            self::assertStringContainsString(ReachableSiblingFailureSource::class . '->' . ReachableSiblingFailureDto::class, $exception->getMessage());
            self::assertStringNotContainsString(ReleaseCollectionSource::class . '->' . ReleaseCollectionDto::class, $exception->getMessage());
            self::assertStringNotContainsString('attacker-secret', $exception->getMessage());
        }
    }

    public function testItPreparesNestedDependenciesBeforeCollectionIteration(): void
    {
        $definitions = [
            new MappingDefinition(Release::class, ReleaseDto::class),
            new MappingDefinition(RegistryCountingCollectionSource::class, ReleaseCollectionDto::class, [
                'releases' => MapRule::fromGetter('getReleases')->collection(Release::class, ReleaseDto::class),
            ]),
        ];
        $countingMappingRegistry  = new CountingMappingRegistry(new MappingRegistry($definitions));
        $valueTransformerRegistry = new ValueTransformerRegistry();
        $mapperCache              = new MapperCache(
            new MappingMetadataFactory($valueTransformerRegistry, mappingRegistry: $countingMappingRegistry),
            new PhpMapperGenerator(),
            $this->cacheDirectory,
            $valueTransformerRegistry,
            true,
            $countingMappingRegistry,
        );
        $objectMapper                     = new ObjectMapper($countingMappingRegistry, $mapperCache);
        $registryCountingCollectionSource = new RegistryCountingCollectionSource($countingMappingRegistry, [
            new Release('first'),
            new Release('second'),
            new Release('third'),
        ]);

        $releaseCollectionDto = $objectMapper->map($registryCountingCollectionSource, ReleaseCollectionDto::class);

        self::assertCount(3, $releaseCollectionDto->releases);
        self::assertSame($registryCountingCollectionSource->registryCallsWhenRead(), $countingMappingRegistry->getCalls());
    }

    public function testItRetainsPrecomputedGrandchildDependenciesAcrossNestedScopes(): void
    {
        $definitions = [
            new MappingDefinition(ThreeLevelLeafSource::class, ThreeLevelLeafDto::class),
            new MappingDefinition(ThreeLevelMiddleSource::class, ThreeLevelMiddleDto::class, [
                'child' => MapRule::from('child')->nested(ThreeLevelLeafDto::class),
            ]),
            new MappingDefinition(RegistryCountingRootSource::class, ThreeLevelRootDto::class, [
                'child' => MapRule::fromGetter('getChild')->nested(ThreeLevelMiddleDto::class),
            ]),
        ];
        $countingMappingRegistry  = new CountingMappingRegistry(new MappingRegistry($definitions));
        $valueTransformerRegistry = new ValueTransformerRegistry();
        $mapperCache              = new MapperCache(
            new MappingMetadataFactory($valueTransformerRegistry, mappingRegistry: $countingMappingRegistry),
            new PhpMapperGenerator(),
            $this->cacheDirectory,
            $valueTransformerRegistry,
            true,
            $countingMappingRegistry,
        );
        $objectMapper               = new ObjectMapper($countingMappingRegistry, $mapperCache);
        $registryCountingRootSource = new RegistryCountingRootSource(
            $countingMappingRegistry,
            new ThreeLevelMiddleSource(new ThreeLevelLeafSource('leaf')),
        );

        $threeLevelRootDto = $objectMapper->map($registryCountingRootSource, ThreeLevelRootDto::class);

        self::assertSame('leaf', $threeLevelRootDto->child->child->label);
        self::assertSame($registryCountingRootSource->registryCallsWhenRead(), $countingMappingRegistry->getCalls());
    }

    public function testItKeepsStructuralRuntimeBindingsOutOfPublicMetadata(): void
    {
        $customMappingDefinition = new CustomMappingDefinition(Release::class, ReleaseDto::class, new StatefulReleaseMapper('release-'));
        $mappingDefinition       = new MappingDefinition(ReleaseCollectionSource::class, ReleaseCollectionDto::class, [
            'releases' => MapRule::from('releases')->collection(Release::class, ReleaseDto::class),
        ]);
        $mappingRegistry         = new MappingRegistry([
            $customMappingDefinition,
            $mappingDefinition,
        ]);
        $mappingMetadataFactory = new MappingMetadataFactory(mappingRegistry: $mappingRegistry);
        $mappingMetadata        = $mappingMetadataFactory->create($mappingDefinition);
        $nestedMappingMetadata  = $mappingMetadata->parameters[0]->nestedMapping;

        self::assertInstanceOf(NestedMappingMetadata::class, $nestedMappingMetadata);
        self::assertFalse((new ReflectionClass(NestedMappingMetadata::class))->hasProperty('dependencyRuntimeBinding'));
        self::assertFalse((new ReflectionClass(MappingMetadataFactory::class))->hasMethod('compiledDependencyBinding'));
        self::assertNull($mappingMetadataFactory->compiledDependencyMetadata($mappingMetadata, $nestedMappingMetadata));
    }

    public function testItReusesCompiledMetadataForDiamondDependencies(): void
    {
        $mappingDefinitions = [
            new MappingDefinition(ExactChild::class, ExactChildDto::class),
            new MappingDefinition(DiamondBranchSource::class, DiamondBranchDto::class, [
                'child' => MapRule::from('child')->nested(ExactChildDto::class),
            ]),
            new MappingDefinition(DiamondRootSource::class, DiamondRootDto::class, [
                'left'  => MapRule::from('left')->nested(DiamondBranchDto::class),
                'right' => MapRule::from('right')->nested(DiamondBranchDto::class),
            ]),
        ];
        $mappingRegistry        = new MappingRegistry($mappingDefinitions);
        $mappingMetadataFactory = new MappingMetadataFactory(mappingRegistry: $mappingRegistry);
        $mappingMetadata        = $mappingMetadataFactory->create($mappingDefinitions[2]);
        $leftNestedMapping      = $mappingMetadata->parameters[0]->nestedMapping;
        $rightNestedMapping     = $mappingMetadata->parameters[1]->nestedMapping;

        self::assertInstanceOf(NestedMappingMetadata::class, $leftNestedMapping);
        self::assertInstanceOf(NestedMappingMetadata::class, $rightNestedMapping);
        $leftBranchMetadata  = $mappingMetadataFactory->compiledDependencyMetadata($mappingMetadata, $leftNestedMapping);
        $rightBranchMetadata = $mappingMetadataFactory->compiledDependencyMetadata($mappingMetadata, $rightNestedMapping);
        self::assertInstanceOf(MappingMetadata::class, $leftBranchMetadata);
        self::assertSame($leftBranchMetadata, $rightBranchMetadata);

        $leafNestedMapping = $leftBranchMetadata->parameters[0]->nestedMapping;
        self::assertInstanceOf(NestedMappingMetadata::class, $leafNestedMapping);
        self::assertInstanceOf(
            MappingMetadata::class,
            $mappingMetadataFactory->compiledDependencyMetadata($leftBranchMetadata, $leafNestedMapping),
        );
    }

    #[DataProvider('collectionCacheModes')]
    public function testUnconsumedCollectionDiagnosticDoesNotRetainRequestScopedCollaboratorAfterRootUnwinds(bool $reusePreparedMappings): void
    {
        if (function_exists('xdebug_info') && in_array('develop', xdebug_info('mode'), true)) {
            self::markTestSkipped('Xdebug retains exception arguments; run XDEBUG_MODE=off to verify runtime retention.');
        }
        $mappingRegistry = new MappingRegistry([
            new MappingDefinition(Release::class, ReleaseDto::class),
            new MappingDefinition(UnconsumedCollectionDiagnosticSource::class, ReleaseCollectionDto::class, [
                'releases' => MapRule::fromGetter('getReleases')->collection(Release::class, ReleaseDto::class),
            ]),
        ]);
        $valueTransformerRegistry = new ValueTransformerRegistry();
        $mapperCache              = new MapperCache(
            new MappingMetadataFactory($valueTransformerRegistry, mappingRegistry: $mappingRegistry),
            new PhpMapperGenerator(), $this->cacheDirectory, $valueTransformerRegistry,
            generateOnDemand: true, mappingRegistry: $mappingRegistry, reusePreparedMappings: $reusePreparedMappings,
        );
        $objectMapper                                      = new ObjectMapper($mappingRegistry, $mapperCache);
        $capturedDiagnostic                                = null;
        $accessToken                                       = new AccessToken('request-scoped-collaborator');
        $unconsumedCollectionDiagnosticSource              = new UnconsumedCollectionDiagnosticSource(
            $mapperCache,
            $accessToken,
            static function(GeneratedMappingExecutionFailed $generatedMappingExecutionFailed) use (&$capturedDiagnostic): void {
                $capturedDiagnostic = $generatedMappingExecutionFailed;
            },
        );
        $weakReference    = WeakReference::create($accessToken);
        $weakSource       = WeakReference::create($unconsumedCollectionDiagnosticSource);

        self::assertEquals(new ReleaseCollectionDto([]), $objectMapper->map($unconsumedCollectionDiagnosticSource, ReleaseCollectionDto::class));
        self::assertInstanceOf(GeneratedMappingExecutionFailed::class, $capturedDiagnostic);

        unset($unconsumedCollectionDiagnosticSource, $accessToken);

        self::assertNull($weakSource->get());
        self::assertNull($weakReference->get());
    }

    private function orderMapper(): ObjectMapper
    {
        return $this->mapper(
            true,
            new MappingDefinition(Release::class, ReleaseDto::class),
            new MappingDefinition(ConstructorOrderSource::class, ConstructorOrderTarget::class, [
                'child' => MapRule::fromGetter('getChild')->nested(ReleaseDto::class),
                'items' => MapRule::fromGetter('getItems')->collection(Release::class, ReleaseDto::class),
            ]),
        );
    }
}
