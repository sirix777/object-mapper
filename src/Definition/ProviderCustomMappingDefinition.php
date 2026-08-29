<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Definition;

use InvalidArgumentException;
use ReflectionClass;
use Sirix\ObjectMapper\Contract\MappingDefinitionInterface;

use function class_exists;
use function sprintf;
use function trim;

final readonly class ProviderCustomMappingDefinition implements MappingDefinitionInterface
{
    /**
     * @param class-string $source
     * @param class-string $target
     */
    public function __construct(
        private string $source,
        private string $target,
        private string $mapperId,
    ) {
        $this->assertConcreteClass($source, 'source');
        $this->assertConcreteClass($target, 'target');

        if ('' === trim($mapperId)) {
            throw new InvalidArgumentException('Custom mapper identifier must not be empty.');
        }
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

    public function mapperId(): string
    {
        return $this->mapperId;
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
