<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Metadata;

use Closure;
use LogicException;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use Traversable;

use function array_map;
use function array_push;
use function class_exists;
use function implode;
use function interface_exists;
use function is_a;
use function sprintf;

/** @internal */
final class TypeCompatibilityChecker
{
    /**
     * @param ReflectionClass<object> $sourceContext
     * @param ReflectionClass<object> $targetContext
     */
    public function isCompatible(
        ReflectionType $source,
        ReflectionClass $sourceContext,
        ReflectionType $target,
        ReflectionClass $targetContext,
    ): bool {
        foreach ($this->toDnf($source, $sourceContext) as $sourceClause) {
            $compatible = false;

            foreach ($this->toDnf($target, $targetContext) as $targetClause) {
                if ($this->clauseIsAssignableTo($sourceClause, $targetClause)) {
                    $compatible = true;

                    break;
                }
            }

            if (! $compatible) {
                return false;
            }
        }

        return true;
    }

    /** @param ReflectionClass<object> $reflectionClass */
    public function describe(ReflectionType $reflectionType, ReflectionClass $reflectionClass): string
    {
        $clauses = $this->toDnf($reflectionType, $reflectionClass);

        return implode('|', array_map(
            static fn (array $clause): string => implode('&', $clause),
            $clauses,
        ));
    }

    /**
     * @param ReflectionClass<object> $reflectionClass
     *
     * @return list<list<string>> a union of intersections
     */
    private function toDnf(ReflectionType $reflectionType, ReflectionClass $reflectionClass): array
    {
        if ($reflectionType instanceof ReflectionNamedType) {
            $name    = $this->resolveName($reflectionType->getName(), $reflectionClass);
            $clauses = [[$name]];

            if ($reflectionType->allowsNull() && 'null' !== $name && 'mixed' !== $name) {
                $clauses[] = ['null'];
            }

            return $clauses;
        }

        if ($reflectionType instanceof ReflectionUnionType) {
            $clauses = [];
            foreach ($reflectionType->getTypes() as $unionMember) {
                array_push($clauses, ...$this->toDnf($unionMember, $reflectionClass));
            }

            return $clauses;
        }

        if ($reflectionType instanceof ReflectionIntersectionType) {
            $clause = [];
            foreach ($reflectionType->getTypes() as $intersectionMember) {
                foreach ($this->toDnf($intersectionMember, $reflectionClass) as $memberClause) {
                    array_push($clause, ...$memberClause);
                }
            }

            return [$clause];
        }

        throw new LogicException('Unsupported reflection type.');
    }

    /** @param ReflectionClass<object> $reflectionClass */
    private function resolveName(string $name, ReflectionClass $reflectionClass): string
    {
        return match ($name) {
            'self',
            'static' => $reflectionClass->getName(),
            'parent' => $this->parentName($reflectionClass),
            default  => $name,
        };
    }

    /** @param ReflectionClass<object> $reflectionClass */
    private function parentName(ReflectionClass $reflectionClass): string
    {
        $parent = $reflectionClass->getParentClass();
        if (false === $parent) {
            throw new LogicException(sprintf('%s has no parent class.', $reflectionClass->getName()));
        }

        return $parent->getName();
    }

    /**
     * @param list<string> $sourceClause
     * @param list<string> $targetClause
     */
    private function clauseIsAssignableTo(array $sourceClause, array $targetClause): bool
    {
        foreach ($targetClause as $targetAtom) {
            $satisfied = false;
            foreach ($sourceClause as $sourceAtom) {
                if ($this->atomIsAssignableTo($sourceAtom, $targetAtom)) {
                    $satisfied = true;

                    break;
                }
            }

            if (! $satisfied) {
                return false;
            }
        }

        return true;
    }

    private function atomIsAssignableTo(string $source, string $target): bool
    {
        if ('mixed' === $target || 'never' === $source) {
            return true;
        }

        if ('mixed' === $source) {
            return 'mixed' === $target;
        }

        if ($source === $target) {
            return true;
        }

        if (('true' === $source || 'false' === $source) && 'bool' === $target) {
            return true;
        }

        if ('object' === $target) {
            return $this->isClassOrInterface($source);
        }

        if ('iterable' === $target) {
            return 'array' === $source || ($this->isClassOrInterface($source) && is_a($source, Traversable::class, true));
        }

        if ('callable' === $target) {
            return 'callable' === $source || Closure::class === $source;
        }

        return $this->isClassOrInterface($source)
            && $this->isClassOrInterface($target)
            && is_a($source, $target, true);
    }

    private function isClassOrInterface(string $type): bool
    {
        return class_exists($type) || interface_exists($type);
    }
}
