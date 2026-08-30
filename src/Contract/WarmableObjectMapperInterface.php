<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Contract;

interface WarmableObjectMapperInterface extends ObjectMapperInterface
{
    /** @return list<string> */
    public function warmup(): array;
}
