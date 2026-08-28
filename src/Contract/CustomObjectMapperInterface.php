<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Contract;

interface CustomObjectMapperInterface
{
    public function map(object $source): object;
}
