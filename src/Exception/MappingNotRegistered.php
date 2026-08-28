<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Exception;

use RuntimeException;

use function sprintf;

final class MappingNotRegistered extends RuntimeException
{
    /**
     * @param class-string $source
     * @param class-string $target
     */
    public function __construct(
        public readonly string $source,
        public readonly string $target,
    ) {
        parent::__construct(sprintf('Mapping from "%s" to "%s" is not registered.', $source, $target));
    }
}
