<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Definition;

use InvalidArgumentException;
use ReflectionClass;
use Sirix\ObjectMapper\Contract\ValueTransformerInterface;

use function class_exists;
use function is_a;
use function preg_match;
use function sprintf;
use function str_starts_with;
use function strlen;

final readonly class MapRule
{
    private const PROPERTY = 'property';

    private const GETTER = 'getter';

    private const METHOD = 'method';

    /** @param null|class-string<ValueTransformerInterface> $transformer */
    private function __construct(
        private string $selector,
        private string $kind,
        private ?string $transformer = null,
    ) {}

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

    public static function fromMethod(string $method): self
    {
        self::assertIdentifier($method, 'method');

        return new self($method, self::METHOD);
    }

    /**
     * @param class-string<ValueTransformerInterface> $transformer
     *
     * @phpstan-param string $transformer
     */
    public function through(string $transformer): self
    {
        if (null !== $this->transformer) {
            throw new InvalidArgumentException('A mapping rule can have only one transformer.');
        }

        if (! class_exists($transformer) || ! is_a($transformer, ValueTransformerInterface::class, true)) {
            throw new InvalidArgumentException(sprintf(
                'Mapping rule transformer "%s" must be a class implementing %s.',
                $transformer,
                ValueTransformerInterface::class,
            ));
        }

        if ((new ReflectionClass($transformer))->isAnonymous()) {
            throw new InvalidArgumentException('Mapping rule transformers must be named classes.');
        }

        return new self($this->selector, $this->kind, $transformer);
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

    public function selectsMethod(): bool
    {
        return self::METHOD === $this->kind;
    }

    public function hasTransformer(): bool
    {
        return null !== $this->transformer;
    }

    /** @return null|class-string<ValueTransformerInterface> */
    public function transformer(): ?string
    {
        return $this->transformer;
    }

    private static function assertIdentifier(string $identifier, string $kind): void
    {
        if (1 !== preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $identifier)) {
            throw new InvalidArgumentException(sprintf('Mapping rule %s "%s" must be a valid identifier.', $kind, $identifier));
        }
    }
}
