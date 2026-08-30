<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Definition;

use Sirix\ObjectMapper\Contract\CustomObjectMapperInterface;
use Sirix\ObjectMapper\Contract\MappingDefinitionInterface;

final readonly class CustomMappingDefinition implements MappingDefinitionInterface
{
    /**
     * @param class-string $source
     * @param class-string $target
     */
    public function __construct(
        private string $source,
        private string $target,
        public CustomObjectMapperInterface $mapper,
        public SourceMatchMode $sourceMatch = SourceMatchMode::Exact,
    ) {
        DefinitionClassValidator::assertConcrete($source, 'source');
        DefinitionClassValidator::assertConcrete($target, 'target');
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
}
