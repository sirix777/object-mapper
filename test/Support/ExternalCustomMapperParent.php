<?php

declare(strict_types=1);

namespace Sirix\ObjectMapperTest\Support;

use Sirix\ObjectMapper\Contract\CustomObjectMapperInterface;

abstract class ExternalCustomMapperParent implements CustomObjectMapperInterface
{
    public function map(object $source): object
    {
        return new CustomChildDto($source instanceof CustomChildSource ? $source->label : 'unexpected');
    }
}
