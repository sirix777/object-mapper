<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Definition;

use InvalidArgumentException;
use ReflectionClass;

use function class_exists;
use function sprintf;

/** @internal */
final class DefinitionClassValidator
{
    public static function assertConcrete(string $class, string $role, bool $requireNamedClass = false): void
    {
        if (! class_exists($class)) {
            throw new InvalidArgumentException(sprintf('Mapping %s class "%s" does not exist.', $role, $class));
        }

        $reflectionClass = new ReflectionClass($class);

        $canonicalClass = $reflectionClass->getName();

        if ($class !== $canonicalClass) {
            throw new InvalidArgumentException(sprintf('Mapping %s class "%s" must use its canonical class name "%s".', $role, $class, $canonicalClass));
        }

        if ($reflectionClass->isInterface() || $reflectionClass->isAbstract()) {
            throw new InvalidArgumentException(sprintf('Mapping %s class "%s" must be concrete.', $role, $class));
        }

        if ($requireNamedClass && $reflectionClass->isAnonymous()) {
            throw new InvalidArgumentException(sprintf('Mapping %s class "%s" must be a named concrete class.', $role, $class));
        }
    }
}
