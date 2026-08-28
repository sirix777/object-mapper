<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Metadata;

use LogicException;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use Sirix\ObjectMapper\Definition\MappingDefinition;

use Sirix\ObjectMapper\Exception\MappingCompilationFailed;

use function array_push;
use function hash_file;
use function in_array;
use function is_file;
use function sprintf;
use function ucfirst;

/** @internal */
final readonly class MappingMetadataFactory
{
    public function __construct(private TypeCompatibilityChecker $typeCompatibilityChecker = new TypeCompatibilityChecker()) {}

    public function create(MappingDefinition $mappingDefinition): MappingMetadata
    {
        $source      = new ReflectionClass($mappingDefinition->source);
        $target      = new ReflectionClass($mappingDefinition->target);
        $constructor = $target->getConstructor();

        if (null === $constructor || ! $constructor->isPublic()) {
            throw new MappingCompilationFailed(sprintf(
                'Target class %s must have a public constructor.',
                $target->getName(),
            ));
        }

        $parameters = [];
        foreach ($constructor->getParameters() as $reflectionParameter) {
            if ($reflectionParameter->isVariadic()) {
                throw new MappingCompilationFailed($this->message(
                    $source,
                    $target,
                    $reflectionParameter->getName(),
                    'Variadic target parameters are not supported.',
                ));
            }

            $sourceMember  = $this->findSourceMember($source, $reflectionParameter);
            $parameterType = $reflectionParameter->getType();

            if (! $sourceMember instanceof SourceMember) {
                if (! $reflectionParameter->isDefaultValueAvailable()) {
                    throw new MappingCompilationFailed($this->message(
                        $source,
                        $target,
                        $reflectionParameter->getName(),
                        'No safe readable source member was found.',
                    ));
                }

                $parameters[] = new TargetParameter(
                    $reflectionParameter->getName(),
                    null,
                    true,
                    $parameterType,
                    $constructor->getDeclaringClass(),
                );

                continue;
            }

            if (null === $parameterType) {
                throw new MappingCompilationFailed($this->message(
                    $source,
                    $target,
                    $reflectionParameter->getName(),
                    'Target parameter has no declared type.',
                ));
            }

            if (! $this->typeCompatibilityChecker->isCompatible(
                $sourceMember->type,
                $sourceMember->declaringClass,
                $parameterType,
                $constructor->getDeclaringClass(),
            )) {
                throw new MappingCompilationFailed($this->message(
                    $source,
                    $target,
                    $reflectionParameter->getName(),
                    sprintf(
                        'Source type %s is not assignable to target type %s.',
                        $this->typeCompatibilityChecker->describe($sourceMember->type, $sourceMember->declaringClass),
                        $this->typeCompatibilityChecker->describe($parameterType, $constructor->getDeclaringClass()),
                    ),
                ));
            }

            $parameters[] = new TargetParameter(
                $reflectionParameter->getName(),
                $sourceMember,
                $reflectionParameter->isDefaultValueAvailable(),
                $parameterType,
                $constructor->getDeclaringClass(),
            );
        }

        $this->assertNoUnmappedPublicSourceProperties($source, $target, $parameters);

        return new MappingMetadata(
            $source->getName(),
            $target->getName(),
            $parameters,
            $this->fileHash($source),
            $this->fileHash($target),
        );
    }

    /** @param ReflectionClass<object> $reflectionClass */
    private function findSourceMember(ReflectionClass $reflectionClass, ReflectionParameter $reflectionParameter): ?SourceMember
    {
        $name = $reflectionParameter->getName();

        if ($reflectionClass->hasProperty($name)) {
            $property = $reflectionClass->getProperty($name);
            if ($property->isPublic() && ! $property->isStatic() && $property->getType() instanceof ReflectionType) {
                return new SourceMember(
                    $name,
                    'property',
                    $property->getType(),
                    $property->getDeclaringClass(),
                );
            }
        }

        $getter = 'get' . ucfirst($name);
        if ($reflectionClass->hasMethod($getter)) {
            $method = $reflectionClass->getMethod($getter);
            if ($method->isPublic() && ! $method->isStatic() && 0 === $method->getNumberOfParameters() && $method->getReturnType() instanceof ReflectionType) {
                return new SourceMember(
                    $getter,
                    'method',
                    $method->getReturnType(),
                    $this->sourceMemberContext($method->getReturnType(), $method->getDeclaringClass(), $reflectionClass),
                );
            }
        }

        if ($this->isBooleanParameter($reflectionParameter)) {
            $getter = 'is' . ucfirst($name);
            if ($reflectionClass->hasMethod($getter)) {
                $method = $reflectionClass->getMethod($getter);
                if ($method->isPublic() && ! $method->isStatic() && 0 === $method->getNumberOfParameters() && $method->getReturnType() instanceof ReflectionType) {
                    return new SourceMember(
                        $getter,
                        'method',
                        $method->getReturnType(),
                        $this->sourceMemberContext($method->getReturnType(), $method->getDeclaringClass(), $reflectionClass),
                    );
                }
            }
        }

        return null;
    }

    private function isBooleanParameter(ReflectionParameter $reflectionParameter): bool
    {
        $type = $reflectionParameter->getType();
        if (null === $type) {
            return false;
        }

        foreach ($this->typeAtoms($type) as $atom) {
            if ('bool' !== $atom && 'null' !== $atom) {
                return false;
            }
        }

        return in_array('bool', $this->typeAtoms($type), true);
    }

    /** @return list<string> */
    private function typeAtoms(ReflectionType $reflectionType): array
    {
        if ($reflectionType instanceof ReflectionNamedType) {
            return [$reflectionType->getName()];
        }

        if (! $reflectionType instanceof ReflectionUnionType && ! $reflectionType instanceof ReflectionIntersectionType) {
            throw new LogicException('Unsupported reflection type.');
        }

        $atoms = [];
        foreach ($reflectionType->getTypes() as $nestedType) {
            array_push($atoms, ...$this->typeAtoms($nestedType));
        }

        return $atoms;
    }

    /** @param ReflectionClass<object> $reflectionClass */
    private function fileHash(ReflectionClass $reflectionClass): ?string
    {
        $file = $reflectionClass->getFileName();
        if (false === $file || ! is_file($file)) {
            return null;
        }

        $hash = hash_file('sha256', $file);
        if (false === $hash) {
            throw new MappingCompilationFailed(sprintf('Could not hash %s.', $file));
        }

        return $hash;
    }

    /**
     * @param ReflectionClass<object> $source
     * @param ReflectionClass<object> $target
     * @param list<TargetParameter>   $parameters
     */
    private function assertNoUnmappedPublicSourceProperties(
        ReflectionClass $source,
        ReflectionClass $target,
        array $parameters,
    ): void {
        $mappedProperties = [];
        foreach ($parameters as $parameter) {
            if ('property' === $parameter->sourceMember?->kind) {
                $mappedProperties[$parameter->sourceMember->name] = true;
            }
        }

        foreach ($source->getProperties(ReflectionProperty::IS_PUBLIC) as $reflectionProperty) {
            if (! $reflectionProperty->isStatic() && ! isset($mappedProperties[$reflectionProperty->getName()])) {
                throw new MappingCompilationFailed(sprintf(
                    'Cannot compile mapping %s -> %s: public source property $%s is not mapped.',
                    $source->getName(),
                    $target->getName(),
                    $reflectionProperty->getName(),
                ));
            }
        }
    }

    /**
     * @param ReflectionClass<object> $declaringClass
     * @param ReflectionClass<object> $source
     *
     * @return ReflectionClass<object>
     */
    private function sourceMemberContext(
        ReflectionType $reflectionType,
        ReflectionClass $declaringClass,
        ReflectionClass $source,
    ): ReflectionClass {
        return $this->usesStaticType($reflectionType) ? $source : $declaringClass;
    }

    private function usesStaticType(ReflectionType $reflectionType): bool
    {
        if ($reflectionType instanceof ReflectionNamedType) {
            return 'static' === $reflectionType->getName();
        }

        if (! $reflectionType instanceof ReflectionUnionType && ! $reflectionType instanceof ReflectionIntersectionType) {
            throw new LogicException('Unsupported reflection type.');
        }

        foreach ($reflectionType->getTypes() as $nestedType) {
            if ($this->usesStaticType($nestedType)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param ReflectionClass<object> $source
     * @param ReflectionClass<object> $target
     */
    private function message(ReflectionClass $source, ReflectionClass $target, string $parameter, string $reason): string
    {
        return sprintf(
            'Cannot compile mapping %s -> %s for parameter $%s: %s',
            $source->getName(),
            $target->getName(),
            $parameter,
            $reason,
        );
    }
}
