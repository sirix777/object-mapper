<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Definition;

use InvalidArgumentException;
use Sirix\ObjectMapper\Contract\MappingDefinitionInterface;

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
        DefinitionClassValidator::assertConcrete($source, 'source');
        DefinitionClassValidator::assertConcrete($target, 'target');

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
}
