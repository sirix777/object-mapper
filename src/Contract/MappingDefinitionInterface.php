<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Contract;

interface MappingDefinitionInterface
{
    /** @return class-string */
    public function source(): string;

    /** @return class-string */
    public function target(): string;

    public function key(): string;
}
