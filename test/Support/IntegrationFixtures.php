<?php

declare(strict_types=1);

namespace Sirix\ObjectMapperTest\Integration;

use Closure;
use Fiber;
use RuntimeException;
use Sirix\ObjectMapper\Contract\CustomObjectMapperInterface;
use Sirix\ObjectMapper\Contract\MappingDefinitionInterface;
use Sirix\ObjectMapper\Contract\MappingRegistryInterface;
use Sirix\ObjectMapper\Contract\ValueTransformerInterface;
use Sirix\ObjectMapper\Contract\ValueTransformerRegistryInterface;
use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Definition\MapRule;
use Sirix\ObjectMapper\Definition\SourceMatchMode;
use Sirix\ObjectMapper\Generator\MapperCache;
use Sirix\ObjectMapper\Metadata\MappingMetadataFactory;
use Sirix\ObjectMapper\Runtime\GeneratedMappingExecutionFailed;
use Sirix\ObjectMapperTest\Support\AccessToken;
use Sirix\ObjectMapperTest\Support\DefaultSource;
use Sirix\ObjectMapperTest\Support\DefaultTarget;
use Sirix\ObjectMapperTest\Support\MissingTarget;
use Sirix\ObjectMapperTest\Support\Release;
use Sirix\ObjectMapperTest\Support\ReleaseCollectionDto;
use Sirix\ObjectMapperTest\Support\ReleaseCollectionSource;
use Sirix\ObjectMapperTest\Support\ReleaseDto;
use Sirix\ObjectMapperTest\Support\ThreeLevelLeafDto;
use Sirix\ObjectMapperTest\Support\ThreeLevelLeafSource;
use Sirix\ObjectMapperTest\Support\ThreeLevelMiddleSource;
use stdClass;

use Throwable;

class ExactChild
{
    public function __construct(public string $value) {}
}

final class ForgedGetterSource
{
    public function getName(): string
    {
        throw new GeneratedMappingExecutionFailed(new stdClass());
    }
}

final class ExactChildSubclass extends ExactChild {}

final class ExactChildDto
{
    public function __construct(public string $value) {}
}

final class ExactHolderSource
{
    public function __construct(public ExactChild $child) {}
}

final class ExactHolderDto
{
    public function __construct(public ExactChildDto $child) {}
}

final class ExactCollectionSource
{
    /** @param list<ExactChild> $children */
    public function __construct(public array $children) {}
}

final class ExactCollectionDto
{
    /** @param list<ExactChildDto> $children */
    public function __construct(public array $children) {}
}

final class NestedReleaseCollectionSource
{
    public function __construct(public ReleaseCollectionSource $collection) {}
}

final class NestedReleaseCollectionDto
{
    public function __construct(public ReleaseCollectionDto $collection) {}
}

class PolymorphicTarget {}

final class PolymorphicTargetSubtype extends PolymorphicTarget {}

final class PolymorphicHolderSource
{
    /** @param list<ExactChild> $children */
    public function __construct(public ExactChild $child, public array $children) {}
}

final class PolymorphicHolderDto
{
    /** @param list<PolymorphicTarget> $children */
    public function __construct(public PolymorphicTarget $child, public array $children) {}
}

final class InvalidChildHolderDto
{
    public function __construct(public MissingTarget $token) {}
}

final class SecondInvalidChildHolderSource
{
    public function __construct(public AccessToken $token) {}
}

final class SecondInvalidChildHolderDto
{
    public function __construct(public MissingTarget $token) {}
}

final class MaliciousCollectionFailureSource
{
    /** @return list<Release> */
    public function getReleases(): array
    {
        throw new GeneratedMappingExecutionFailed(new stdClass());
    }
}

final readonly class UnconsumedCollectionDiagnosticSource
{
    public function __construct(
        private MapperCache $mapperCache,
        private AccessToken $accessToken,
        private Closure $captureDiagnostic,
    ) {}

    /** @return list<Release> */
    public function getReleases(): array
    {
        try {
            $this->mapperCache->collectionElementTypeFailure(
                self::class,
                ReleaseCollectionDto::class,
                'releases',
                17,
                Release::class,
                $this->accessToken,
            );
        } catch (GeneratedMappingExecutionFailed $diagnostic) {
            ($this->captureDiagnostic)($diagnostic);
        }

        return [];
    }
}

final readonly class RebindingCollectionFailureSource
{
    public function __construct(private MapperCache $mapperCache) {}

    /** @return list<Release> */
    public function getReleases(): array
    {
        try {
            $this->mapperCache->collectionElementTypeFailure(
                self::class,
                ReleaseCollectionDto::class,
                'releases',
                "attacker-secret\n",
                Release::class,
                new AccessToken('attacker-secret'),
            );
        } catch (GeneratedMappingExecutionFailed $exception) {
            throw new GeneratedMappingExecutionFailed($exception->context());
        }
    }
}

final readonly class IndependentCollectionFailureSource
{
    public function __construct(private MapperCache $mapperCache) {}

    /** @return list<Release> */
    public function getReleases(): array
    {
        return $this->mapperCache->collectionElementTypeFailure(
            ReleaseCollectionSource::class,
            ReleaseCollectionDto::class,
            'releases',
            'attacker-secret',
            Release::class,
            new AccessToken('attacker-secret'),
        );
    }
}

final class IndependentCollectionFailureDto
{
    /** @param list<ReleaseDto> $releases */
    public function __construct(public array $releases) {}
}

final class ReachableSiblingFailureSource
{
    public ?ReleaseCollectionSource $sibling = null;

    public function __construct(private readonly MapperCache $mapperCache) {}

    /** @return list<Release> */
    public function getReleases(): array
    {
        return $this->mapperCache->collectionElementTypeFailure(
            ReleaseCollectionSource::class,
            ReleaseCollectionDto::class,
            'releases',
            'attacker-secret',
            Release::class,
            new AccessToken('attacker-secret'),
        );
    }
}

final class ReachableSiblingFailureDto
{
    /** @param list<ReleaseDto> $releases */
    public function __construct(public ?ReleaseCollectionDto $sibling, public array $releases) {}
}

final readonly class FiberCollectionFailureSource
{
    public function __construct(
        private MapperCache $mapperCache,
        private bool $suspend,
        private int $key,
    ) {}

    /** @return list<Release> */
    public function getReleases(): array
    {
        if ($this->suspend) {
            Fiber::suspend();
        }

        return $this->mapperCache->collectionElementTypeFailure(
            self::class,
            FiberCollectionFailureDto::class,
            'releases',
            $this->key,
            Release::class,
            new AccessToken('fiber-secret'),
        );
    }
}

final class FiberCollectionFailureDto
{
    /** @param list<ReleaseDto> $releases */
    public function __construct(public array $releases) {}
}

final class RegistryCountingCollectionSource
{
    private int $registryCallsWhenRead = 0;

    /** @param list<Release> $releases */
    public function __construct(
        private readonly CountingMappingRegistry $countingMappingRegistry,
        private readonly array $releases,
    ) {}

    /** @return list<Release> */
    public function getReleases(): array
    {
        $this->registryCallsWhenRead = $this->countingMappingRegistry->getCalls();

        return $this->releases;
    }

    public function registryCallsWhenRead(): int
    {
        return $this->registryCallsWhenRead;
    }
}

final class CountingMappingRegistry implements MappingRegistryInterface
{
    private int $calls = 0;

    public function __construct(private readonly MappingRegistryInterface $mappingRegistry) {}

    public function get(string $source, string $target): MappingDefinitionInterface
    {
        ++$this->calls;

        return $this->mappingRegistry->get($source, $target);
    }

    public function getCalls(): int
    {
        return $this->calls;
    }

    public function all(): iterable
    {
        return $this->mappingRegistry->all();
    }
}

final class FreshRootDefinitionRegistry implements MappingRegistryInterface
{
    private int $reads = 0;

    public function get(string $source, string $target): MappingDefinitionInterface
    {
        ++$this->reads;

        return new MappingDefinition(FreshRootDefinitionSource::class, FreshRootDefinitionTarget::class, [
            'value' => MapRule::constant(1 === $this->reads ? 'first' : 'second'),
        ], ['value']);
    }

    public function all(): iterable
    {
        return [];
    }
}

final class CountingValueTransformerRegistry implements ValueTransformerRegistryInterface
{
    public int $getCalls = 0;

    public function __construct(private readonly ValueTransformerInterface $valueTransformer) {}

    public function get(string $transformer): ValueTransformerInterface
    {
        ++$this->getCalls;

        return $this->valueTransformer;
    }
}

final class PreparedCacheCountingTransformer implements ValueTransformerInterface
{
    public function transform(string $value): string
    {
        return $value;
    }
}

final readonly class FreshRootDefinitionSource
{
    public function __construct(public string $value) {}
}

final readonly class FreshRootDefinitionTarget
{
    public function __construct(public string $value) {}
}

final class StatefulDependencyRegistry implements MappingRegistryInterface
{
    private int $dependencyReads = 0;

    /** @param class-string $dependencySource @param class-string $dependencyTarget */
    public function __construct(
        private readonly MappingRegistryInterface $mappingRegistry,
        private readonly string $dependencySource,
        private readonly string $dependencyTarget,
        private readonly MappingDefinitionInterface $mappingDefinition,
    ) {}

    public function get(string $source, string $target): MappingDefinitionInterface
    {
        if ($source !== $this->dependencySource || $target !== $this->dependencyTarget) {
            return $this->mappingRegistry->get($source, $target);
        }

        ++$this->dependencyReads;

        return 1 === $this->dependencyReads
            ? $this->mappingRegistry->get($source, $target)
            : $this->mappingDefinition;
    }

    public function all(): iterable
    {
        return $this->mappingRegistry->all();
    }
}

final class WarmupSnapshotRegistry implements MappingRegistryInterface
{
    private int $dependencyReads = 0;

    /** @param class-string $dependencySource @param class-string $dependencyTarget */
    public function __construct(
        private readonly MappingRegistryInterface $mappingRegistry,
        private readonly string $dependencySource,
        private readonly string $dependencyTarget,
        private readonly MappingDefinitionInterface|Throwable $replacement,
    ) {}

    public function get(string $source, string $target): MappingDefinitionInterface
    {
        if ($source !== $this->dependencySource || $target !== $this->dependencyTarget) {
            return $this->mappingRegistry->get($source, $target);
        }

        ++$this->dependencyReads;
        if (1 === $this->dependencyReads) {
            return $this->mappingRegistry->get($source, $target);
        }

        if ($this->replacement instanceof Throwable) {
            throw $this->replacement;
        }

        return $this->replacement;
    }

    public function dependencyReads(): int
    {
        return $this->dependencyReads;
    }

    public function all(): iterable
    {
        return $this->mappingRegistry->all();
    }
}

final class SequentialDependencyRegistry implements MappingRegistryInterface
{
    private int $dependencyReads = 0;

    /** @param class-string $dependencySource @param class-string $dependencyTarget */
    public function __construct(
        private readonly MappingRegistryInterface $mappingRegistry,
        private readonly string $dependencySource,
        private readonly string $dependencyTarget,
        private readonly MappingDefinitionInterface $mappingDefinition,
    ) {}

    public function get(string $source, string $target): MappingDefinitionInterface
    {
        if ($source !== $this->dependencySource || $target !== $this->dependencyTarget) {
            return $this->mappingRegistry->get($source, $target);
        }

        ++$this->dependencyReads;

        return 1 === $this->dependencyReads
            ? $this->mappingRegistry->get($source, $target)
            : $this->mappingDefinition;
    }

    public function all(): iterable
    {
        return $this->mappingRegistry->all();
    }
}

final class ThrowingAllMappingRegistry implements MappingRegistryInterface
{
    public function get(string $source, string $target): MappingDefinitionInterface
    {
        throw new RuntimeException('all-registry-secret');
    }

    public function all(): iterable
    {
        throw new RuntimeException('all-registry-secret');
    }
}

final readonly class HostileDependencyRegistry implements MappingRegistryInterface
{
    /** @param class-string $dependencySource @param class-string $dependencyTarget */
    public function __construct(
        private MappingRegistryInterface $mappingRegistry,
        private string $dependencySource,
        private string $dependencyTarget,
    ) {}

    public function get(string $source, string $target): MappingDefinitionInterface
    {
        if ($source === $this->dependencySource && $target === $this->dependencyTarget) {
            return new HostileMappingDefinition();
        }

        return $this->mappingRegistry->get($source, $target);
    }

    public function all(): iterable
    {
        return $this->mappingRegistry->all();
    }
}

final class HostileMappingDefinition implements MappingDefinitionInterface
{
    public function source(): string
    {
        throw new RuntimeException('hostile-source-secret');
    }

    public function target(): string
    {
        throw new RuntimeException('hostile-target-secret');
    }

    public function key(): string
    {
        throw new RuntimeException('hostile-key-secret');
    }
}

final class ForeignMappingDefinition implements MappingDefinitionInterface
{
    public function source(): string
    {
        return DefaultSource::class;
    }

    public function target(): string
    {
        return DefaultTarget::class;
    }

    public function key(): string
    {
        return DefaultSource::class . '->' . DefaultTarget::class;
    }
}

final class WrongThenCorrectDependencyRegistry implements MappingRegistryInterface
{
    private bool $returnedWrongPair = false;

    /** @param class-string $dependencySource @param class-string $dependencyTarget */
    public function __construct(
        private readonly MappingRegistryInterface $mappingRegistry,
        private readonly string $dependencySource,
        private readonly string $dependencyTarget,
        private readonly MappingDefinitionInterface $wrongPairMappingDefinition,
    ) {}

    public function get(string $source, string $target): MappingDefinitionInterface
    {
        if (! $this->returnedWrongPair
            && $source === $this->dependencySource
            && $target === $this->dependencyTarget) {
            $this->returnedWrongPair = true;

            return $this->wrongPairMappingDefinition;
        }

        return $this->mappingRegistry->get($source, $target);
    }

    public function all(): iterable
    {
        return $this->mappingRegistry->all();
    }
}

final class ReentrantCreateMappingRegistry implements MappingRegistryInterface
{
    private ?MappingMetadataFactory $mappingMetadataFactory = null;

    private ?MappingDefinition $mappingDefinition = null;

    private bool $reentered = false;

    /** @param class-string $dependencySource @param class-string $dependencyTarget */
    public function __construct(
        private readonly MappingRegistryInterface $mappingRegistry,
        private readonly string $dependencySource,
        private readonly string $dependencyTarget,
    ) {}

    public function configureReentry(MappingMetadataFactory $mappingMetadataFactory, MappingDefinition $mappingDefinition): void
    {
        $this->mappingMetadataFactory = $mappingMetadataFactory;
        $this->mappingDefinition      = $mappingDefinition;
    }

    public function get(string $source, string $target): MappingDefinitionInterface
    {
        if (! $this->reentered
            && $source === $this->dependencySource
            && $target === $this->dependencyTarget
            && $this->mappingMetadataFactory instanceof MappingMetadataFactory
            && $this->mappingDefinition instanceof MappingDefinition) {
            $this->reentered = true;
            $this->mappingMetadataFactory->create($this->mappingDefinition);
        }

        return $this->mappingRegistry->get($source, $target);
    }

    public function all(): iterable
    {
        return $this->mappingRegistry->all();
    }
}

final class FiberSuspendingMappingRegistry implements MappingRegistryInterface
{
    private bool $suspended = false;

    /** @param class-string $dependencySource @param class-string $dependencyTarget */
    public function __construct(
        private readonly MappingRegistryInterface $mappingRegistry,
        private readonly string $dependencySource,
        private readonly string $dependencyTarget,
    ) {}

    public function get(string $source, string $target): MappingDefinitionInterface
    {
        if (! $this->suspended
            && $source === $this->dependencySource
            && $target === $this->dependencyTarget
            && Fiber::getCurrent() instanceof Fiber) {
            $this->suspended = true;
            Fiber::suspend();
        }

        return $this->mappingRegistry->get($source, $target);
    }

    public function all(): iterable
    {
        return $this->mappingRegistry->all();
    }
}

final readonly class StatefulReleaseMapper implements CustomObjectMapperInterface
{
    public function __construct(private string $prefix) {}

    public function map(object $source): object
    {
        if (! $source instanceof Release) {
            throw new RuntimeException('Expected a release.');
        }

        return new ReleaseDto($this->prefix . $source->version);
    }
}

final readonly class StatefulLeafMapper implements CustomObjectMapperInterface
{
    public function __construct(private string $prefix) {}

    public function map(object $source): object
    {
        if (! $source instanceof ThreeLevelLeafSource) {
            throw new RuntimeException('Expected a three-level leaf.');
        }

        return new ThreeLevelLeafDto($this->prefix . $source->label);
    }
}

final class RegistryCountingRootSource
{
    private int $registryCallsWhenRead = 0;

    public function __construct(
        private readonly CountingMappingRegistry $countingMappingRegistry,
        private readonly ThreeLevelMiddleSource $threeLevelMiddleSource,
    ) {}

    public function getChild(): ThreeLevelMiddleSource
    {
        $this->registryCallsWhenRead = $this->countingMappingRegistry->getCalls();

        return $this->threeLevelMiddleSource;
    }

    public function registryCallsWhenRead(): int
    {
        return $this->registryCallsWhenRead;
    }
}

final class DiamondBranchSource
{
    public function __construct(public ExactChild $child) {}
}

final class DiamondBranchDto
{
    public function __construct(public ExactChildDto $child) {}
}

final class DiamondRootSource
{
    public function __construct(public DiamondBranchSource $left, public DiamondBranchSource $right) {}
}

final class DiamondRootDto
{
    public function __construct(public DiamondBranchDto $left, public DiamondBranchDto $right) {}
}

final class UnrelatedChildSource
{
    /** @param list<Release> $releases */
    public function __construct(public array $releases) {}
}

final readonly class CacheMapFailureSource
{
    public function __construct(
        private MapperCache $mapperCache,
        private MappingDefinition $mappingDefinition,
    ) {}

    /** @return list<Release> */
    public function getReleases(): array
    {
        $createChild = (static fn (mixed $releases): UnrelatedChildSource => new UnrelatedChildSource($releases));
        $this->mapperCache->map($this->mappingDefinition, $createChild([new AccessToken('attacker-secret')]));

        return [];
    }
}

final readonly class UnrelatedNestedDispatchSource
{
    public function __construct(private MapperCache $mapperCache) {}

    /** @return list<Release> */
    public function getReleases(): array
    {
        $createChild = (static fn (mixed $releases): UnrelatedChildSource => new UnrelatedChildSource($releases));
        $this->mapperCache->mapNested(
            $createChild([new AccessToken('attacker-secret')]),
            UnrelatedChildSource::class,
            ReleaseCollectionDto::class,
            SourceMatchMode::Exact,
        );

        return [];
    }
}

final class NullableGetterHolderSource
{
    private int $reads = 0;

    public function __construct(private readonly ?ExactChild $exactChild) {}

    public function getChild(): ?ExactChild
    {
        ++$this->reads;

        return $this->exactChild;
    }

    public function readCount(): int
    {
        return $this->reads;
    }
}

final class NullableGetterHolderDto
{
    public function __construct(public ?ExactChildDto $child) {}
}

final class CollectionHelperCollisionSource
{
    /**
     * @param list<ExactChild> $items
     * @param list<ExactChild> $Items
     */
    public function __construct(public array $items, public array $Items) {}
}

final class CollectionHelperCollisionDto
{
    /**
     * @param list<ExactChildDto> $items
     * @param list<ExactChildDto> $Items
     */
    public function __construct(public array $items, public array $Items) {}
}

final class SwappableDependencyRegistry implements MappingRegistryInterface
{
    public ?MappingDefinitionInterface $replacement = null;

    /** @param class-string $dependencySource @param class-string $dependencyTarget */
    public function __construct(
        private readonly MappingRegistryInterface $mappingRegistry,
        private readonly string $dependencySource,
        private readonly string $dependencyTarget,
    ) {}

    public function get(string $source, string $target): MappingDefinitionInterface
    {
        if ($this->replacement instanceof MappingDefinitionInterface && $source === $this->dependencySource && $target === $this->dependencyTarget) {
            return $this->replacement;
        }

        return $this->mappingRegistry->get($source, $target);
    }

    public function all(): iterable
    {
        return $this->mappingRegistry->all();
    }
}
