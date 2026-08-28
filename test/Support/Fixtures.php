<?php

declare(strict_types=1);

namespace Sirix\ObjectMapperTest\Support;

use DateTimeInterface;
use LogicException;
use RuntimeException;
use Sirix\ObjectMapper\Contract\ValueTransformerInterface;
use Sirix\ObjectMapper\Exception\MappingExecutionFailed;

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
