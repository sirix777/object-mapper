<?php

declare(strict_types=1);

namespace Sirix\ObjectMapperTest\Support;

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
