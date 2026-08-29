<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Runtime;

use Sirix\ObjectMapper\Exception\MappingExecutionFailed;

/** @internal */
final class GeneratedMappingExecutionFailed extends MappingExecutionFailed
{
    public function __construct(
        private readonly object $context,
    ) {
        parent::__construct('Generated collection element validation failed.');
    }

    /** @internal */
    public function context(): object
    {
        return $this->context;
    }
}
