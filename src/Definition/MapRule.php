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

    private const DIRECT = 'direct';

    private const TRANSFORM = 'transform';

    private const NESTED = 'nested';

    private const COLLECTION = 'collection';

    /**
     * @param null|class-string<ValueTransformerInterface> $transformer
     * @param 'collection'|'direct'|'nested'|'transform'   $operation
     * @param null|class-string                            $nestedTarget
     * @param null|class-string                            $collectionElementSource
     * @param null|class-string                            $collectionElementTarget
     */
    private function __construct(private string $selector, private string $kind, private ?string $transformer = null, private string $operation = self::DIRECT, private ?string $nestedTarget = null, private ?string $collectionElementSource = null, private ?string $collectionElementTarget = null) {}

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

        if (self::DIRECT !== $this->operation) {
            throw new InvalidArgumentException('A mapping rule terminal operation cannot be combined with a transformer.');
        }

        $transformer = $this->transformerClass($transformer);

        return new self($this->selector, $this->kind, $transformer, self::TRANSFORM);
    }

    public function nested(string $target): self
    {
        $this->assertStructuralOperationCanBeConfigured();
        $target = $this->concreteClass($target, 'nested target');

        return new self($this->selector, $this->kind, operation: self::NESTED, nestedTarget: $target);
    }

    public function collection(string $elementSource, string $elementTarget): self
    {
        $this->assertStructuralOperationCanBeConfigured();
        $elementSource = $this->concreteClass($elementSource, 'collection element source');
        $elementTarget = $this->concreteClass($elementTarget, 'collection element target');

        return new self(
            $this->selector,
            $this->kind,
            operation: self::COLLECTION,
            collectionElementSource: $elementSource,
            collectionElementTarget: $elementTarget,
        );
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
        return self::TRANSFORM === $this->operation;
    }

    /** @return 'collection'|'direct'|'nested'|'transform' */
    public function operation(): string
    {
        return $this->operation;
    }

    /** @return null|class-string<ValueTransformerInterface> */
    public function transformer(): ?string
    {
        return $this->transformer;
    }

    public function isNested(): bool
    {
        return self::NESTED === $this->operation;
    }

    public function isCollection(): bool
    {
        return self::COLLECTION === $this->operation;
    }

    /** @return null|class-string */
    public function nestedTarget(): ?string
    {
        return $this->nestedTarget;
    }

    /** @return null|class-string */
    public function collectionElementSource(): ?string
    {
        return $this->collectionElementSource;
    }

    /** @return null|class-string */
    public function collectionElementTarget(): ?string
    {
        return $this->collectionElementTarget;
    }

    private function assertStructuralOperationCanBeConfigured(): void
    {
        if (self::DIRECT !== $this->operation) {
            throw new InvalidArgumentException('A mapping rule can have only one terminal operation.');
        }
    }

    /** @return class-string */
    private function concreteClass(string $class, string $role): string
    {
        $this->assertClassName($class, $role);

        if (! class_exists($class)) {
            throw new InvalidArgumentException(sprintf('Mapping rule %s class "%s" does not exist.', $role, $class));
        }

        $reflectionClass = new ReflectionClass($class);

        if ($reflectionClass->isInterface() || $reflectionClass->isAbstract() || $reflectionClass->isAnonymous()) {
            throw new InvalidArgumentException(sprintf('Mapping rule %s class "%s" must be a named concrete class.', $role, $class));
        }

        $canonicalClass = $reflectionClass->getName();

        if ($class !== $canonicalClass) {
            throw new InvalidArgumentException(sprintf('Mapping rule %s class "%s" must use its canonical class name "%s".', $role, $class, $canonicalClass));
        }

        return $canonicalClass;
    }

    /** @return class-string<ValueTransformerInterface> */
    private function transformerClass(string $transformer): string
    {
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

        return $transformer;
    }

    private static function assertIdentifier(string $identifier, string $kind): void
    {
        if (1 !== preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $identifier)) {
            throw new InvalidArgumentException(sprintf('Mapping rule %s "%s" must be a valid identifier.', $kind, $identifier));
        }
    }

    private function assertClassName(string $class, string $role): void
    {
        if (1 !== preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\\\[a-zA-Z_][a-zA-Z0-9_]*)*$/D', $class)) {
            throw new InvalidArgumentException(sprintf('Mapping rule %s class "%s" must be a valid canonical class name.', $role, $class));
        }
    }
}
