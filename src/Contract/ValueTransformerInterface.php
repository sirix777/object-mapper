<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Contract;

/**
 * Marker interface for values that can be transformed during mapping.
 *
 * The concrete transform() method is intentionally validated by reflection.
 */
interface ValueTransformerInterface {}
