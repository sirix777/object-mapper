<?php

declare(strict_types=1);

namespace Sirix\ObjectMapperTest\Support;

final class VirtualSetOnlySource
{
    public int $value {
        set(int $value) {
        }
    }
}

final class VirtualSetOnlyWithGetterSource
{
    public int $value {
        set(int $value) {
        }
    }

    public function getValue(): int
    {
        return 7;
    }
}

final class VirtualGetOnlySource
{
    public int $value {
        get => 42;
    }
}

final class VirtualGetSetSource
{
    public static int $reads = 0;

    public int $value {
        get {
            ++self::$reads;

            return $this->stored;
        }

        set(int $value) {
            $this->stored = $value;
        }
    }

    private int $stored = 3;
}

final class BackedSetOnlySource
{
    public int $value = 1 {
        set(int $value) {
            $this->value = $value;
        }
    }
}

final class HookValueTarget
{
    public function __construct(public int $value) {}
}
