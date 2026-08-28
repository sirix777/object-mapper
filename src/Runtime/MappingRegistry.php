<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Runtime;

use InvalidArgumentException;
use Sirix\ObjectMapper\Contract\MappingDefinitionInterface;
use Sirix\ObjectMapper\Contract\MappingRegistryInterface;
use Sirix\ObjectMapper\Exception\MappingNotRegistered;

use function get_debug_type;
use function sprintf;

final class MappingRegistry implements MappingRegistryInterface
{
    /**
     * @var array<string, MappingDefinitionInterface>
     */
    private array $definitions = [];

    /**
     * @param iterable<MappingDefinitionInterface> $definitions
     */
    public function __construct(iterable $definitions)
    {
        foreach ($definitions as $definition) {
            $key = $definition->key();

            if (isset($this->definitions[$key])) {
                throw new InvalidArgumentException(sprintf(
                    'Mapping from "%s" to "%s" is already registered by %s; cannot register %s.',
                    $definition->source(),
                    $definition->target(),
                    get_debug_type($this->definitions[$key]),
                    get_debug_type($definition),
                ));
            }

            $this->definitions[$key] = $definition;
        }
    }

    public function get(string $source, string $target): MappingDefinitionInterface
    {
        $key = $source . '->' . $target;

        if (! isset($this->definitions[$key])) {
            throw new MappingNotRegistered($source, $target);
        }

        return $this->definitions[$key];
    }

    public function all(): iterable
    {
        return $this->definitions;
    }
}
