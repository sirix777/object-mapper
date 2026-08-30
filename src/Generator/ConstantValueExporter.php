<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Generator;

use LogicException;
use Sirix\ObjectMapper\Metadata\ConstantValueMetadata;

use function implode;
use function is_finite;
use function is_float;
use function is_string;
use function ord;
use function sprintf;
use function str_replace;
use function strlen;
use function substr;
use function var_export;

/** @internal */
final class ConstantValueExporter
{
    public function export(ConstantValueMetadata $constantValueMetadata): string
    {
        if ('null' === $constantValueMetadata->kind) {
            return 'null';
        }

        if ('bool' === $constantValueMetadata->kind) {
            return $constantValueMetadata->value ? 'true' : 'false';
        }

        if ('int' === $constantValueMetadata->kind) {
            return (string) $constantValueMetadata->value;
        }

        if ('float' === $constantValueMetadata->kind) {
            return $this->float($constantValueMetadata->value);
        }

        return $this->string($constantValueMetadata->value);
    }

    private function float(bool|float|int|string|null $value): string
    {
        if (! is_float($value) || ! is_finite($value)) {
            throw new LogicException('Constant float metadata must be finite.');
        }

        return var_export($value, true);
    }

    private function string(bool|float|int|string|null $value): string
    {
        if (! is_string($value)) {
            throw new LogicException('Constant string metadata must contain a string.');
        }

        $segments = [];
        $start    = 0;
        for ($index = 0; $index < strlen($value); ++$index) {
            $byte = ord($value[$index]);
            if ($byte >= 32 && 127 !== $byte) {
                continue;
            }

            $segments[] = $this->singleQuoted(substr($value, $start, $index - $start));
            $segments[] = sprintf('"\x%02X"', $byte);
            $start      = $index + 1;
        }

        $segments[] = $this->singleQuoted(substr($value, $start));

        return implode(' . ', $segments);
    }

    private function singleQuoted(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }
}
