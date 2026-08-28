<?php

declare(strict_types=1);

namespace Sirix\ObjectMapperTest\Support;

use DateTimeInterface;
use Sirix\ObjectMapper\Contract\ValueTransformerInterface;

abstract class ExternalTransformingParent implements ValueTransformerInterface
{
    public function transform(DateTimeInterface $value): string
    {
        return $value->format(DATE_ATOM);
    }
}
