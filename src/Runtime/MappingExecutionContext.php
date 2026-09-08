<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Runtime;

/** @internal */
final class MappingExecutionContext
{
    public ?MappingExecutionFrame $currentFrame = null;
}
