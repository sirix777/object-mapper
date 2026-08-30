<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Metadata;

use LogicException;

use function bin2hex;
use function gettype;
use function is_bool;
use function is_finite;
use function is_float;
use function is_int;
use function is_string;

/** @internal */
final readonly class ConstantValueMetadata
{
    /**
     * @param 'bool'|'float'|'int'|'null'|'string' $kind
     */
    private function __construct(public string $kind, public bool|float|int|string|null $value) {}

    /** @return array{kind: 'bool'|'float'|'int'|'null'|'string', value: null|array{encoding: 'hex', data: string}|bool|float|int} */
    public function identity(): array
    {
        if ('null' === $this->kind) {
            if (null !== $this->value) {
                throw new LogicException('Constant null metadata must contain null.');
            }

            return [
                'kind'  => $this->kind,
                'value' => null,
            ];
        }

        if ('bool' === $this->kind) {
            if (! is_bool($this->value)) {
                throw new LogicException('Constant bool metadata must contain a bool.');
            }

            return [
                'kind'  => $this->kind,
                'value' => $this->value,
            ];
        }

        if ('int' === $this->kind) {
            if (! is_int($this->value)) {
                throw new LogicException('Constant int metadata must contain an int.');
            }

            return [
                'kind'  => $this->kind,
                'value' => $this->value,
            ];
        }

        if ('float' === $this->kind) {
            if (! is_float($this->value)) {
                throw new LogicException('Constant float metadata must contain a float.');
            }

            return [
                'kind'  => $this->kind,
                'value' => $this->value,
            ];
        }

        if (! is_string($this->value)) {
            throw new LogicException('Constant string metadata must contain a string.');
        }

        return [
            'kind'  => $this->kind,
            'value' => [
                'encoding' => 'hex',
                'data'     => bin2hex($this->value),
            ],
        ];
    }

    public static function fromValue(bool|float|int|string|null $value): self
    {
        if (is_float($value) && ! is_finite($value)) {
            throw new LogicException('Constant float metadata must be finite.');
        }

        return new self(self::kind($value), $value);
    }

    /** @return 'bool'|'float'|'int'|'null'|'string' */
    private static function kind(mixed $value): string
    {
        return match (gettype($value)) {
            'NULL'    => 'null',
            'boolean' => 'bool',
            'integer' => 'int',
            'double'  => 'float',
            'string'  => 'string',
            default   => throw new LogicException('Unsupported constant value.'),
        };
    }
}
