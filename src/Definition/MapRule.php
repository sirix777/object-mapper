<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Definition;

use InvalidArgumentException;

use function preg_match;
use function sprintf;
use function str_starts_with;
use function strlen;

final readonly class MapRule
{
    private const PROPERTY = 'property';

    private const GETTER = 'getter';

    private function __construct(private string $selector, private string $kind) {}

    public static function from(string $property): self
    {
        self::assertIdentifier($property, 'property');

        return new self($property, self::PROPERTY);
    }

    public static function fromGetter(string $getter): self
    {
        self::assertIdentifier($getter, 'getter');

        if (! str_starts_with($getter, 'get') || 3 === strlen($getter)) {
            throw new InvalidArgumentException(sprintf('Mapping rule getter "%s" must use the get* naming convention.', $getter));
        }

        return new self($getter, self::GETTER);
    }

    public function selector(): string
    {
        return $this->selector;
    }

    public function selectsProperty(): bool
    {
        return self::PROPERTY === $this->kind;
    }

    public function selectsGetter(): bool
    {
        return self::GETTER === $this->kind;
    }

    private static function assertIdentifier(string $identifier, string $kind): void
    {
        if (1 !== preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $identifier)) {
            throw new InvalidArgumentException(sprintf('Mapping rule %s "%s" must be a valid identifier.', $kind, $identifier));
        }
    }
}
