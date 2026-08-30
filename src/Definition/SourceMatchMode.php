<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Definition;

enum SourceMatchMode
{
    case Exact;
    case CycleProxy;
}
