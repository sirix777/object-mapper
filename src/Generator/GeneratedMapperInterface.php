<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Generator;

/** @internal */
interface GeneratedMapperInterface
{
    public function map(object $source): object;
}
