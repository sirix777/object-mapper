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
use Sirix\ObjectMapper\Definition\MapRule;

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

        $this->assertAllRulesTargetParameters($source, $target, $mappingDefinition->rules, $constructor->getParameters());
        $this->assertIgnoredSourceProperties($source, $target, $mappingDefinition->ignoredSource);

        $parameters = [];
        $rules      = $mappingDefinition->rules;
        foreach ($constructor->getParameters() as $reflectionParameter) {
            $mapRule = $rules[$reflectionParameter->getName()] ?? null;
            if ($reflectionParameter->isVariadic()) {
                throw new MappingCompilationFailed($this->message(
                    $source,
                    $target,
                    $reflectionParameter->getName(),
                    sprintf(
                        '%sVariadic target parameters are not supported.',
                        $mapRule instanceof MapRule ? sprintf('Configured selector %s: ', $this->describeRule($mapRule)) : '',
                    ),
                ));
            }

            $sourceMember  = $this->findSourceMember($source, $target, $reflectionParameter, $mapRule);
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
                    sprintf(
                        '%sTarget parameter has no declared type.',
                        $mapRule instanceof MapRule ? sprintf('Configured selector %s: ', $this->describeRule($mapRule)) : '',
                    ),
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
                        '%sSource type %s is not assignable to target type %s.',
                        $mapRule instanceof MapRule ? sprintf('Configured selector %s: ', $this->describeRule($mapRule)) : '',
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

        $this->assertNoUnmappedPublicSourceProperties($source, $target, $parameters, $mappingDefinition->ignoredSource);

        return new MappingMetadata(
            $source->getName(),
            $target->getName(),
            $parameters,
            $this->fileHash($source),
            $this->fileHash($target),
        );
    }

    /**
     * @param ReflectionClass<object> $reflectionClass
     * @param ReflectionClass<object> $target
     */
    private function findSourceMember(
        ReflectionClass $reflectionClass,
        ReflectionClass $target,
        ReflectionParameter $reflectionParameter,
        ?MapRule $mapRule,
    ): ?SourceMember {
        if ($mapRule instanceof MapRule) {
            return $this->resolveRule($reflectionClass, $target, $reflectionParameter, $mapRule);
        }

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

    /**
     * @param ReflectionClass<object> $source
     * @param ReflectionClass<object> $target
     */
    private function resolveRule(
        ReflectionClass $source,
        ReflectionClass $target,
        ReflectionParameter $reflectionParameter,
        MapRule $mapRule,
    ): SourceMember {
        $selector = $mapRule->selector();

        if ($mapRule->selectsProperty()) {
            if (! $source->hasProperty($selector)) {
                throw new MappingCompilationFailed($this->message(
                    $source,
                    $target,
                    $reflectionParameter->getName(),
                    sprintf('Configured property selector $%s does not exist.', $selector),
                ));
            }

            $property = $source->getProperty($selector);
            if (! $property->isPublic() || $property->isStatic() || ! $property->getType() instanceof ReflectionType) {
                throw new MappingCompilationFailed($this->message(
                    $source,
                    $target,
                    $reflectionParameter->getName(),
                    sprintf('Configured property selector $%s must select a public, non-static, typed source property.', $selector),
                ));
            }

            return new SourceMember($selector, 'property', $property->getType(), $property->getDeclaringClass(), 'property_rule');
        }

        if (! $source->hasMethod($selector)) {
            throw new MappingCompilationFailed($this->message(
                $source,
                $target,
                $reflectionParameter->getName(),
                sprintf('Configured getter selector %s() does not exist.', $selector),
            ));
        }

        $reflectionMethod = $source->getMethod($selector);
        if (! $reflectionMethod->isPublic() || $reflectionMethod->isStatic() || 0 !== $reflectionMethod->getNumberOfParameters() || ! $reflectionMethod->getReturnType() instanceof ReflectionType) {
            throw new MappingCompilationFailed($this->message(
                $source,
                $target,
                $reflectionParameter->getName(),
                sprintf('Configured getter selector %s() must select a public, non-static, zero-argument method with a declared return type.', $selector),
            ));
        }

        return new SourceMember(
            $selector,
            'method',
            $reflectionMethod->getReturnType(),
            $this->sourceMemberContext($reflectionMethod->getReturnType(), $reflectionMethod->getDeclaringClass(), $source),
            'getter_rule',
        );
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
     * @param list<string>            $ignoredSource
     */
    private function assertNoUnmappedPublicSourceProperties(
        ReflectionClass $source,
        ReflectionClass $target,
        array $parameters,
        array $ignoredSource,
    ): void {
        $mappedProperties = [];
        foreach ($parameters as $parameter) {
            if ('property' === $parameter->sourceMember?->kind) {
                $mappedProperties[$parameter->sourceMember->name] = true;
            }
        }

        foreach ($source->getProperties(ReflectionProperty::IS_PUBLIC) as $reflectionProperty) {
            if (! $reflectionProperty->isStatic()
                && ! isset($mappedProperties[$reflectionProperty->getName()])
                && ! in_array($reflectionProperty->getName(), $ignoredSource, true)) {
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
     * @param ReflectionClass<object>   $source
     * @param ReflectionClass<object>   $target
     * @param array<string, MapRule>    $rules
     * @param list<ReflectionParameter> $parameters
     */
    private function assertAllRulesTargetParameters(ReflectionClass $source, ReflectionClass $target, array $rules, array $parameters): void
    {
        $parameterNames = [];
        foreach ($parameters as $parameter) {
            $parameterNames[$parameter->name] = true;
        }

        foreach ($rules as $parameter => $mapRule) {
            if (! isset($parameterNames[$parameter])) {
                throw new MappingCompilationFailed($this->message(
                    $source,
                    $target,
                    $parameter,
                    sprintf(
                        'Configured selector %s does not refer to a target constructor parameter.',
                        $this->describeRule($mapRule),
                    ),
                ));
            }
        }
    }

    private function describeRule(MapRule $mapRule): string
    {
        return $mapRule->selectsProperty() ? '$' . $mapRule->selector() : $mapRule->selector() . '()';
    }

    /**
     * @param ReflectionClass<object> $source
     * @param ReflectionClass<object> $target
     * @param list<string>            $ignoredSource
     */
    private function assertIgnoredSourceProperties(ReflectionClass $source, ReflectionClass $target, array $ignoredSource): void
    {
        foreach ($ignoredSource as $propertyName) {
            if (! $source->hasProperty($propertyName)) {
                throw new MappingCompilationFailed(sprintf(
                    'Cannot compile mapping %s -> %s: ignored source property $%s does not exist.',
                    $source->getName(),
                    $target->getName(),
                    $propertyName,
                ));
            }

            $property = $source->getProperty($propertyName);
            if (! $property->isPublic() || $property->isStatic()) {
                throw new MappingCompilationFailed(sprintf(
                    'Cannot compile mapping %s -> %s: ignored source property $%s must be public and non-static.',
                    $source->getName(),
                    $target->getName(),
                    $propertyName,
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
