<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Definition;

use InvalidArgumentException;
use ReflectionClass;

use function class_exists;
use function sprintf;

final readonly class MappingDefinition
{
    /**
     * @param class-string $source
     * @param class-string $target
     */
    public function __construct(
        public string $source,
        public string $target,
    ) {
        $this->assertConcreteClass($source, 'source');
        $this->assertConcreteClass($target, 'target');
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
}
