<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Contract;

interface CustomObjectMapperProviderInterface
{
    /**
     * Resolves an application-allowlisted mapper identifier to an already constructed mapper.
     *
     * The identifier is opaque to the core and must not be treated as a class name or a
     * general-purpose service lookup key.
     */
    public function get(string $mapperId): CustomObjectMapperInterface;
}
