<?php

declare(strict_types=1);

namespace Sirix\ObjectMapperTest\Support;

use DateTimeInterface;
use LogicException;
use RuntimeException;
use Sirix\ObjectMapper\Contract\CustomObjectMapperInterface;
use Sirix\ObjectMapper\Contract\CustomObjectMapperProviderInterface;
use Sirix\ObjectMapper\Contract\ValueTransformerInterface;
use Sirix\ObjectMapper\Exception\MappingExecutionFailed;

use function json_decode;

final readonly class ConventionalSource
{
    public function __construct(public int $id, public string $name, public bool $active) {}
}

final readonly class ConventionalTarget
{
    public function __construct(public int $id, public string $name, public bool $active) {}
}

final class GetterSource
{
    public function getName(): string
    {
        return 'Ada';
    }
}

final readonly class NameTarget
{
    public function __construct(public string $name) {}
}

final class BooleanGetterSource
{
    public function isActive(): bool
    {
        return true;
    }
}

final readonly class BooleanTarget
{
    public function __construct(public bool $active) {}
}

final readonly class StringTarget
{
    public function __construct(public string $active) {}
}

final readonly class DefaultSource
{
    public function __construct(public int $id) {}
}

final readonly class DefaultTarget
{
    public function __construct(public int $id, public string $label = 'default') {}
}

final class ConstantSource {}

final readonly class ConstantTarget
{
    public function __construct(public string $value) {}
}

final readonly class NullableConstantTarget
{
    public function __construct(public ?string $value) {}
}

final readonly class UnionConstantTarget
{
    public function __construct(public int|string $value) {}
}

final readonly class MixedConstantTarget
{
    public function __construct(public mixed $value) {}
}

final readonly class ScalarConstantsTarget
{
    public function __construct(
        public ?string $nullable,
        public bool $enabled,
        public int $rank,
        public float $ratio,
        public string $label,
        public int|string $union,
    ) {}
}

final readonly class DefaultConstantTarget
{
    public function __construct(public string $value = 'default') {}
}

final class UntypedConstantTarget
{
    /** @param mixed $value */
    public function __construct(public $value) {}
}

final class VariadicConstantTarget
{
    /** @var array<int|string, string> */
    public array $values;

    public function __construct(string ...$value)
    {
        $this->values = $value;
    }
}

final readonly class SameNamedConstantSource
{
    public function __construct(public string $value) {}
}

final readonly class MissingTarget
{
    public function __construct(public int $missing) {}
}

final class PrivateSource {}

final readonly class IdTarget
{
    public function __construct(public int $id) {}
}

final readonly class NullableSource
{
    public function __construct(public ?string $name) {}
}

final readonly class NonNullableNameTarget
{
    public function __construct(public string $name) {}
}

final readonly class MixedSource
{
    public function __construct(public mixed $name) {}
}

class ParentValue {}

final class ChildValue extends ParentValue {}

final readonly class InheritedSource
{
    public function __construct(public ChildValue $value) {}
}

final readonly class ParentTarget
{
    public function __construct(public ParentValue $value) {}
}

class StaticGetterParent
{
    public function getValue(): static
    {
        return $this;
    }
}

final class StaticGetterSource extends StaticGetterParent {}

final readonly class StaticGetterTarget
{
    public function __construct(public StaticGetterSource $value) {}
}

final readonly class ProfileSource
{
    public function __construct(public int $uuid, public string $passwordHash) {}

    public function getPrimaryEmail(): string
    {
        return 'ada@example.test';
    }
}

final readonly class ProfileTarget
{
    public function __construct(public int $id, public string $email) {}
}

final class RulePrecedenceSource
{
    public function __construct(public int $id, public int $uuid) {}

    public function getEmail(): string
    {
        return 'conventional@example.test';
    }

    public function getPrimaryEmail(): string
    {
        return 'profile@example.test';
    }
}

final class IncompatibleProfileSource
{
    public function __construct(public string $uuid, public string $passwordHash) {}

    public function getPrimaryEmail(): string
    {
        return 'ada@example.test';
    }
}

final class InvalidProfileSource
{
    public static string $staticName = 'Ada';
    private int $privateId           = 1;

    public function getWithParameter(string $name): string
    {
        return $name;
    }

    public function getPrivateId(): int
    {
        return $this->privateId;
    }
}

final class ThrowingGetterSource
{
    public function getName(): string
    {
        throw new MappingExecutionFailed('sensitive value');
    }
}

final class VariadicProfileTarget
{
    /** @var array<int|string, int> */
    public array $ids;

    public function __construct(int ...$id)
    {
        $this->ids = $id;
    }
}

abstract class AbstractFixture {}

final readonly class Uuid
{
    public function __construct(private string $value) {}

    public function toString(): string
    {
        return $this->value;
    }
}

class UuidToStringTransformer implements ValueTransformerInterface
{
    public function transform(Uuid $uuid): string
    {
        return $uuid->toString();
    }
}

final class AlternativeUuidToStringTransformer implements ValueTransformerInterface
{
    public function transform(Uuid $uuid): string
    {
        return $uuid->toString();
    }
}

final class UuidToStringTransformerChild extends UuidToStringTransformer {}

final class DateTimeToAtomTransformer implements ValueTransformerInterface
{
    public function transform(DateTimeInterface $value): string
    {
        return $value->format(DATE_ATOM);
    }
}

final class InheritedDateTimeTransformer extends ExternalTransformingParent {}

final class IncompatibleTransformer implements ValueTransformerInterface
{
    public function transform(int $value): string
    {
        return (string) $value;
    }
}

final class NullableInputTransformer implements ValueTransformerInterface
{
    public function transform(?Uuid $uuid): string
    {
        return $uuid?->toString() ?? 'none';
    }
}

final class NullableOutputTransformer implements ValueTransformerInterface
{
    public function transform(Uuid $uuid): ?string
    {
        $string = $uuid->toString();

        return '' === $string ? null : $string;
    }
}

final class ThrowingTransformer implements ValueTransformerInterface
{
    public function transform(Uuid $uuid): string
    {
        throw new RuntimeException('sensitive transformer value: ' . $uuid->toString());
    }
}

final class PrivateTransformTransformer implements ValueTransformerInterface
{
    public function invokeForFixture(Uuid $uuid): string
    {
        return $this->transform($uuid);
    }

    private function transform(Uuid $uuid): string
    {
        return $uuid->toString();
    }
}

final class StaticTransformTransformer implements ValueTransformerInterface
{
    public static function transform(Uuid $uuid): string
    {
        return $uuid->toString();
    }
}

final class VariadicTransformTransformer implements ValueTransformerInterface
{
    public function transform(Uuid ...$value): string
    {
        return $value[0]->toString();
    }
}

final class ZeroArgumentTransformTransformer implements ValueTransformerInterface
{
    public function transform(): string
    {
        return 'value';
    }
}

final class MultipleArgumentTransformTransformer implements ValueTransformerInterface
{
    public function transform(Uuid $uuid, string $prefix): string
    {
        return $prefix . $uuid->toString();
    }
}

final class ByReferenceTransformTransformer implements ValueTransformerInterface
{
    public function transform(Uuid &$uuid): string
    {
        return $uuid->toString();
    }
}

final class UntypedParameterTransformTransformer implements ValueTransformerInterface
{
    /** @param mixed $value */
    public function transform($value): string
    {
        return (string) $value;
    }
}

final class UntypedReturnTransformTransformer implements ValueTransformerInterface
{
    /** @return string */
    public function transform(Uuid $uuid)
    {
        return $uuid->toString();
    }
}

final class VoidTransformTransformer implements ValueTransformerInterface
{
    public static ?Uuid $last = null;

    public function transform(Uuid $uuid): void
    {
        self::$last = $uuid;
    }
}

final class NeverTransformTransformer implements ValueTransformerInterface
{
    public function transform(Uuid $uuid): never
    {
        throw new LogicException();
    }
}

final class MixedOutputTransformer implements ValueTransformerInterface
{
    public function transform(Uuid $uuid): mixed
    {
        return $uuid->toString();
    }
}

final readonly class ExplicitMethodSource
{
    public function __construct(private Uuid $uuid, private DateTimeInterface $createdAt) {}

    public function getIdentifier(): Uuid
    {
        return $this->uuid;
    }

    public function createdAt(): DateTimeInterface
    {
        return $this->createdAt;
    }

    public function slug(): string
    {
        return 'explicit-slug';
    }
}

final readonly class ExplicitMethodTarget
{
    public function __construct(public string $id, public string $createdAt, public string $slug) {}
}

final class InvalidMethodSource
{
    public static function staticMethod(): string
    {
        return 'static';
    }

    public function argumentful(string $value): string
    {
        return $value;
    }

    /** @return string */
    public function untyped()
    {
        return 'untyped';
    }

    public function invokeHiddenForFixture(): string
    {
        return $this->hidden();
    }

    private function hidden(): string
    {
        return 'hidden';
    }
}

final readonly class NullableUuidMethodSource
{
    public function __construct(private ?Uuid $uuid) {}

    public function nullableIdentifier(): ?Uuid
    {
        return $this->uuid;
    }
}

final readonly class UuidTarget
{
    public function __construct(public Uuid $id) {}
}

final readonly class AccessToken
{
    public function __construct(public string $value) {}
}

final readonly class ApiAccessTokenDto
{
    public function __construct(public string $value) {}
}

final readonly class TokenHolderSource
{
    public function __construct(public AccessToken $token) {}
}

final readonly class TokenHolderDto
{
    public function __construct(public ApiAccessTokenDto $token) {}
}

final readonly class NullableTokenHolderSource
{
    public function __construct(public ?AccessToken $token) {}
}

final readonly class NullableTokenHolderDto
{
    public function __construct(public ?ApiAccessTokenDto $token) {}
}

final readonly class WrongTypedTokenHolderSource
{
    public function __construct(public Uuid $token) {}
}

final readonly class UnionTypedTokenHolderSource
{
    public function __construct(public AccessToken|Uuid $token) {}
}

interface IntersectionTokenLeft {}

interface IntersectionTokenRight {}

final class IntersectionToken implements IntersectionTokenLeft, IntersectionTokenRight {}

final readonly class IntersectionTypedTokenHolderSource
{
    public function __construct(public IntersectionTokenLeft&IntersectionTokenRight $token) {}
}

final class UntypedTokenHolderSource
{
    /** @param mixed $token */
    public function __construct(public $token) {}
}

final readonly class DefaultNestedTokenHolderDto
{
    public function __construct(public ApiAccessTokenDto $token = new ApiAccessTokenDto('default')) {}
}

final readonly class Release
{
    public function __construct(public string $version) {}
}

final readonly class ReleaseDto
{
    public function __construct(public string $version) {}
}

final readonly class ReleaseCollectionSource
{
    /** @param array<int|string, Release> $releases */
    public function __construct(public array $releases) {}
}

final readonly class ReleaseCollectionDto
{
    /** @param list<ReleaseDto> $releases */
    public function __construct(public array $releases) {}
}

final readonly class IterableReleaseCollectionSource
{
    /** @param iterable<Release> $releases */
    public function __construct(public iterable $releases) {}
}

final readonly class IterableReleaseCollectionDto
{
    /** @param iterable<ReleaseDto> $releases */
    public function __construct(public iterable $releases) {}
}

final readonly class AlternativeRelease
{
    public function __construct(public string $version) {}
}

final readonly class AlternativeReleaseDto
{
    public function __construct(public string $version) {}
}

final readonly class SelectorChildSource
{
    public function __construct(public string $value) {}

    public function getValue(): string
    {
        return $this->value;
    }
}

final readonly class SelectorChildDto
{
    public function __construct(public string $value) {}
}

final readonly class SelectorChildHolderSource
{
    public function __construct(public SelectorChildSource $child) {}
}

final readonly class SelectorChildHolderDto
{
    public function __construct(public SelectorChildDto $child) {}
}

final readonly class NullableReleaseCollectionSource
{
    /** @param null|array<int|string, Release> $releases */
    public function __construct(public ?array $releases) {}
}

final readonly class NullableReleaseCollectionDto
{
    /** @param null|list<ReleaseDto> $releases */
    public function __construct(public ?array $releases) {}
}

final readonly class WrongElementReleaseCollectionSource
{
    /** @param array<int|string, object> $releases */
    public function __construct(public array $releases) {}
}

final readonly class CustomChildSource
{
    public function __construct(public string $label) {}
}

final readonly class CustomChildDto
{
    public function __construct(public string $label) {}
}

final readonly class CustomChildHolderSource
{
    public function __construct(public CustomChildSource $child) {}
}

final readonly class CustomChildHolderDto
{
    public function __construct(public CustomChildDto $child) {}
}

final class RecordingCustomChildMapper implements CustomObjectMapperInterface
{
    public int $invocations = 0;

    public function map(object $source): object
    {
        ++$this->invocations;

        return new CustomChildDto($source instanceof CustomChildSource ? $source->label : 'unexpected');
    }
}

final class RecordingCustomMapperProvider implements CustomObjectMapperProviderInterface
{
    public int $lookups = 0;

    /** @var list<string> */
    public array $mapperIds = [];

    /** @param array<string, CustomObjectMapperInterface> $mappers */
    public function __construct(private readonly array $mappers) {}

    public function get(string $mapperId): CustomObjectMapperInterface
    {
        ++$this->lookups;
        $this->mapperIds[] = $mapperId;

        if (! isset($this->mappers[$mapperId])) {
            throw new RuntimeException('Unexpected mapper ID: ' . $mapperId);
        }

        return $this->mappers[$mapperId];
    }
}

final class ThrowingCustomMapperProvider implements CustomObjectMapperProviderInterface
{
    public int $lookups = 0;

    public function get(string $mapperId): CustomObjectMapperInterface
    {
        ++$this->lookups;

        throw new RuntimeException('Provider must not be resolved during warmup.');
    }
}

final class InvalidCustomMapperProvider implements CustomObjectMapperProviderInterface
{
    public function get(string $mapperId): CustomObjectMapperInterface
    {
        return json_decode($mapperId);
    }
}

final class ProviderMapperLabelService
{
    public function label(string $label): string
    {
        return 'service:' . $label;
    }
}

final class ServiceDependentProviderCustomMapper implements CustomObjectMapperInterface
{
    public int $invocations = 0;

    public function __construct(private readonly ProviderMapperLabelService $providerMapperLabelService) {}

    public function map(object $source): object
    {
        ++$this->invocations;

        if (! $source instanceof CustomChildSource) {
            throw new RuntimeException('Unexpected service-dependent custom mapping source.');
        }

        return new CustomChildDto($this->providerMapperLabelService->label($source->label));
    }
}

final class RecordingProviderCustomMapper implements CustomObjectMapperInterface
{
    public int $invocations = 0;

    public function map(object $source): object
    {
        ++$this->invocations;

        return match (true) {
            $source instanceof CustomChildSource => new CustomChildDto($source->label),
            $source instanceof Release           => new ReleaseDto($source->version),
            default                              => throw new RuntimeException('Unexpected provider-backed custom mapping source.'),
        };
    }
}

final class InheritedCustomChildMapper extends ExternalCustomMapperParent {}

final class ThreeLevelRootSource
{
    public function __construct(public ThreeLevelMiddleSource $child) {}
}

final class ThreeLevelMiddleSource
{
    public function __construct(public ThreeLevelLeafSource $child) {}
}

final class ThreeLevelLeafSource
{
    public function __construct(public string $label) {}
}

final class ThreeLevelRootDto
{
    public function __construct(public ThreeLevelMiddleDto $child) {}
}

final class ThreeLevelMiddleDto
{
    public function __construct(public ThreeLevelLeafDto $child) {}
}

final class ThreeLevelLeafDto
{
    public function __construct(public string $label) {}
}

final class SelfCycleSource
{
    public function __construct(public ?self $child = null) {}
}

final class SelfCycleDto
{
    public function __construct(public ?self $child = null) {}
}

final class IndirectCycleSourceA
{
    public IndirectCycleSourceB $child;
}

final class IndirectCycleSourceB
{
    public IndirectCycleSourceC $child;
}

final class IndirectCycleSourceC
{
    public IndirectCycleSourceA $child;
}

final class IndirectCycleDtoA
{
    public function __construct(public IndirectCycleDtoB $child) {}
}

final class IndirectCycleDtoB
{
    public function __construct(public IndirectCycleDtoC $child) {}
}

final class IndirectCycleDtoC
{
    public function __construct(public IndirectCycleDtoA $child) {}
}
