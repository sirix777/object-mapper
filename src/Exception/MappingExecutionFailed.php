<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Exception;

use RuntimeException;
use Throwable;

class MappingExecutionFailed extends RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        private readonly ?MappingFailureReason $reason = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function reason(): ?MappingFailureReason
    {
        return $this->reason;
    }
}
