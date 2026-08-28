<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Generator;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionType;

/** @internal */
final class ReflectionTypeExporter
{
    /** @param ReflectionClass<object> $reflectionClass */
    public function export(ReflectionType $reflectionType, ReflectionClass $reflectionClass): string
    {
        if ($reflectionType instanceof ReflectionNamedType) {
            $name = match ($reflectionType->getName()) {
                'self', 'static' => $reflectionClass->getName(),
                'parent'         => $this->parentName($reflectionClass),
                default          => $reflectionType->getName(),
            };

            return $reflectionType->allowsNull() && 'mixed' !== $name && 'null' !== $name
                ? '?' . $name
                : $name;
        }

        return (string) $reflectionType;
    }

    /** @param ReflectionClass<object> $reflectionClass */
    private function parentName(ReflectionClass $reflectionClass): string
    {
        $parent = $reflectionClass->getParentClass();

        return false === $parent ? 'parent' : $parent->getName();
    }
}
