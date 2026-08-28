<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Exception;

use RuntimeException;

use function sprintf;

final class ValueTransformerNotRegistered extends RuntimeException
{
    /**
     * @param class-string $transformer
     */
    public function __construct(public readonly string $transformer)
    {
        parent::__construct(sprintf('Value transformer "%s" is not registered.', $transformer));
    }
}
