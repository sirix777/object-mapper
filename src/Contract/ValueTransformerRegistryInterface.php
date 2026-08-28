<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Contract;

interface ValueTransformerRegistryInterface
{
    /**
     * @param class-string<ValueTransformerInterface> $transformer
     */
    public function get(string $transformer): ValueTransformerInterface;
}
