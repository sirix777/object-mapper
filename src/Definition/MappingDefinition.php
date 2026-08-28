<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Definition;

use InvalidArgumentException;
use ReflectionClass;
use Sirix\ObjectMapper\Contract\MappingDefinitionInterface;

use function class_exists;
use function is_string;
use function preg_match;
use function sprintf;

final readonly class MappingDefinition implements MappingDefinitionInterface
{
    /**
     * @param class-string           $source
     * @param class-string           $target
     * @param array<string, MapRule> $rules
     * @param list<string>           $ignoredSource
     */
    public function __construct(
        public string $source,
        public string $target,
        public array $rules = [],
        public array $ignoredSource = [],
    ) {
        $this->assertConcreteClass($source, 'source');
        $this->assertConcreteClass($target, 'target');
        $this->assertRules($rules);
        $this->assertIgnoredSource($ignoredSource);
    }

    /** @return class-string */
    public function source(): string
    {
        return $this->source;
    }

    /** @return class-string */
    public function target(): string
    {
        return $this->target;
    }

    public function key(): string
    {
        return $this->source . '->' . $this->target;
    }

    private function assertConcreteClass(string $class, string $role): void
    {
        if (! class_exists($class)) {
            throw new InvalidArgumentException(sprintf('Mapping %s class "%s" does not exist.', $role, $class));
        }

        $reflectionClass = new ReflectionClass($class);

        if ($reflectionClass->isInterface() || $reflectionClass->isAbstract()) {
            throw new InvalidArgumentException(sprintf('Mapping %s class "%s" must be concrete.', $role, $class));
        }
    }

    /** @param array<mixed> $rules */
    private function assertRules(array $rules): void
    {
        foreach ($rules as $targetParameter => $rule) {
            if (! is_string($targetParameter) || ! $this->isIdentifier($targetParameter)) {
                throw new InvalidArgumentException(sprintf('Mapping rule target parameter "%s" must be a valid identifier.', (string) $targetParameter));
            }

            if (! $rule instanceof MapRule) {
                throw new InvalidArgumentException(sprintf('Mapping rule for target parameter "%s" must be an instance of %s.', $targetParameter, MapRule::class));
            }
        }
    }

    /** @param array<mixed> $ignoredSource */
    private function assertIgnoredSource(array $ignoredSource): void
    {
        $seen = [];
        foreach ($ignoredSource as $property) {
            if (! is_string($property) || ! $this->isIdentifier($property)) {
                throw new InvalidArgumentException('Ignored source property must be a valid string identifier.');
            }

            if (isset($seen[$property])) {
                throw new InvalidArgumentException(sprintf('Ignored source property "%s" is configured more than once.', $property));
            }

            $seen[$property] = true;
        }
    }

    private function isIdentifier(string $identifier): bool
    {
        return 1 === preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $identifier);
    }
}
