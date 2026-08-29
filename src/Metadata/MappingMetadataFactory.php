<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Metadata;

use LogicException;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use Sirix\ObjectMapper\Contract\MappingDefinitionInterface;
use Sirix\ObjectMapper\Contract\MappingRegistryInterface;
use Sirix\ObjectMapper\Contract\ValueTransformerRegistryInterface;
use Sirix\ObjectMapper\Definition\CustomMappingDefinition;
use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Definition\MapRule;
use Sirix\ObjectMapper\Definition\ProviderCustomMappingDefinition;

use Sirix\ObjectMapper\Exception\MappingCompilationFailed;
use Sirix\ObjectMapper\Runtime\ValueTransformerRegistry;
use stdClass;
use Throwable;

use WeakMap;

use function array_pop;
use function array_push;
use function array_search;
use function array_slice;
use function class_exists;
use function hash;
use function hash_file;
use function implode;
use function in_array;
use function is_a;
use function is_file;
use function json_encode;
use function ksort;
use function sprintf;
use function ucfirst;

/** @internal */
final class MappingMetadataFactory
{
    private ?object $activeCompilation = null;

    /** @var WeakMap<MappingCompilationFailed, true> */
    private WeakMap $reentrantCompilationFailures;

    /** @var WeakMap<MappingCompilationFailed, true> */
    private WeakMap $trustedCompilationFailures;

    /** @var list<string> */
    private array $dependencyStack = [];

    /** @var array<string, string> */
    private array $dependencyFingerprints = [];

    /** @var array<string, MappingDefinitionInterface> */
    private array $dependencyDefinitions = [];

    /** @var array<string, MappingMetadata> */
    private array $compiledMetadata = [];

    /** @var array<string, true> */
    private array $compilingMetadata = [];

    /** @var WeakMap<NestedMappingMetadata, MappingDefinitionInterface> */
    private WeakMap $dependencyBindings;

    /** @var WeakMap<NestedMappingMetadata, MappingMetadata> */
    private WeakMap $dependencyMetadata;

    /** @var WeakMap<MappingMetadata, array{bindings: WeakMap<NestedMappingMetadata, MappingDefinitionInterface>, metadata: WeakMap<NestedMappingMetadata, MappingMetadata>}> */
    private WeakMap $dependencySnapshots;

    public function __construct(
        private readonly ValueTransformerRegistryInterface $valueTransformerRegistry = new ValueTransformerRegistry(),
        private readonly TypeCompatibilityChecker $typeCompatibilityChecker = new TypeCompatibilityChecker(),
        private readonly ?MappingRegistryInterface $mappingRegistry = null,
    ) {
        $this->dependencyBindings             = new WeakMap();
        $this->dependencyMetadata             = new WeakMap();
        $this->reentrantCompilationFailures   = new WeakMap();
        $this->trustedCompilationFailures     = new WeakMap();
        $this->dependencySnapshots            = new WeakMap();
    }

    public function create(MappingDefinition $mappingDefinition): MappingMetadata
    {
        if (null !== $this->activeCompilation) {
            $mappingCompilationFailed                                      = new MappingCompilationFailed('Mapping metadata compilation is already active.');
            $this->reentrantCompilationFailures[$mappingCompilationFailed] = true;

            throw $mappingCompilationFailed;
        }

        $compilation             = new stdClass();
        $this->activeCompilation = $compilation;

        // Fingerprints are valid only for one dependency traversal. A registry may
        // deliberately provide different definitions between separate compilations.
        try {
            $this->dependencyStack        = [];
            $this->dependencyFingerprints = [];
            $this->dependencyDefinitions  = [];
            $this->compiledMetadata       = [];
            $this->compilingMetadata      = [];
            $this->dependencyBindings     = new WeakMap();
            $this->dependencyMetadata     = new WeakMap();

            return $this->compile($mappingDefinition);
        } catch (MappingCompilationFailed $mappingCompilationFailed) {
            $this->trustedCompilationFailures[$mappingCompilationFailed] = true;

            throw $mappingCompilationFailed;
        } finally {
            if ($this->activeCompilation === $compilation) {
                $this->activeCompilation = null;
            }
        }
    }

    /** @internal */
    public function matchesCompiledDependency(MappingMetadata $mappingMetadata, NestedMappingMetadata $nestedMappingMetadata, MappingDefinitionInterface $mappingDefinition): bool
    {
        $binding = $this->dependencySnapshot($mappingMetadata)['bindings'][$nestedMappingMetadata] ?? null;

        return $binding === $mappingDefinition
            && $mappingDefinition->source() === $nestedMappingMetadata->source
            && $mappingDefinition->target() === $nestedMappingMetadata->target;
    }

    /** @internal */
    public function compiledDependencyMetadata(MappingMetadata $mappingMetadata, NestedMappingMetadata $nestedMappingMetadata): ?MappingMetadata
    {
        return $this->dependencySnapshot($mappingMetadata)['metadata'][$nestedMappingMetadata] ?? null;
    }

    /** @internal */
    public function compiledConventionalDependency(MappingMetadata $mappingMetadata, NestedMappingMetadata $nestedMappingMetadata): ?MappingDefinition
    {
        $binding = $this->dependencySnapshot($mappingMetadata)['bindings'][$nestedMappingMetadata] ?? null;

        return $binding instanceof MappingDefinition ? $binding : null;
    }

    /** @internal */
    public function hasCompiledCustomDependency(MappingMetadata $mappingMetadata, NestedMappingMetadata $nestedMappingMetadata): bool
    {
        $binding = $this->dependencySnapshot($mappingMetadata)['bindings'][$nestedMappingMetadata] ?? null;

        return $binding instanceof CustomMappingDefinition || $binding instanceof ProviderCustomMappingDefinition;
    }

    /** @internal */
    public function trustedCompilationFailureMessage(Throwable $throwable): ?string
    {
        if (! $throwable instanceof MappingCompilationFailed || ! isset($this->trustedCompilationFailures[$throwable])) {
            return null;
        }

        return $throwable->getMessage();
    }

    /** @return null|array{bindings: WeakMap<NestedMappingMetadata, MappingDefinitionInterface>, metadata: WeakMap<NestedMappingMetadata, MappingMetadata>} */
    private function dependencySnapshot(MappingMetadata $mappingMetadata): ?array
    {
        return $this->dependencySnapshots[$mappingMetadata] ?? null;
    }

    private function compile(MappingDefinition $mappingDefinition): MappingMetadata
    {
        $key = $mappingDefinition->key();
        if (isset($this->compiledMetadata[$key])) {
            return $this->compiledMetadata[$key];
        }

        if (isset($this->compilingMetadata[$key])) {
            throw new MappingCompilationFailed(sprintf('Mapping metadata compilation cycle detected for %s.', $key));
        }

        $this->compilingMetadata[$key] = true;

        try {
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

                $transformerMetadata = $mapRule instanceof MapRule && $mapRule->hasTransformer()
                    ? $this->resolveTransformer($source, $target, $reflectionParameter, $mapRule)
                    : null;

                $nestedMappingMetadata = $mapRule instanceof MapRule && ($mapRule->isNested() || $mapRule->isCollection())
                    ? $this->resolveNestedMapping($source, $target, $reflectionParameter, $mapRule, $sourceMember, $parameterType, $constructor->getDeclaringClass())
                    : null;

                if (! $nestedMappingMetadata instanceof NestedMappingMetadata) {
                    $this->assertTypesAreCompatible(
                        $source,
                        $target,
                        $reflectionParameter,
                        $mapRule,
                        $sourceMember,
                        $parameterType,
                        $constructor->getDeclaringClass(),
                        $transformerMetadata,
                    );
                }

                $parameters[] = new TargetParameter(
                    $reflectionParameter->getName(),
                    $sourceMember,
                    $reflectionParameter->isDefaultValueAvailable(),
                    $parameterType,
                    $constructor->getDeclaringClass(),
                    $transformerMetadata,
                    $nestedMappingMetadata,
                );
            }

            $this->assertNoUnmappedPublicSourceProperties($source, $target, $parameters, $mappingDefinition->ignoredSource);

            $mappingMetadata = new MappingMetadata(
                $source->getName(),
                $target->getName(),
                $parameters,
                $this->fileHash($source),
                $this->fileHash($target),
            );
            $this->compiledMetadata[$key]                = $mappingMetadata;
            $this->dependencySnapshots[$mappingMetadata] = [
                'bindings' => $this->dependencyBindings,
                'metadata' => $this->dependencyMetadata,
            ];

            return $mappingMetadata;
        } finally {
            unset($this->compilingMetadata[$key]);
        }
    }

    private function registerNestedDependency(NestedMappingMetadata $nestedMappingMetadata, MappingDefinitionInterface $mappingDefinition): NestedMappingMetadata
    {
        $this->dependencyBindings[$nestedMappingMetadata] = $mappingDefinition;
        if ($mappingDefinition instanceof MappingDefinition) {
            $this->dependencyMetadata[$nestedMappingMetadata] = $this->compile($mappingDefinition);
        }

        return $nestedMappingMetadata;
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

        return $this->resolveSelectedMethod($source, $target, $reflectionParameter, $mapRule);
    }

    /**
     * @param ReflectionClass<object> $source
     * @param ReflectionClass<object> $target
     */
    private function resolveSelectedMethod(
        ReflectionClass $source,
        ReflectionClass $target,
        ReflectionParameter $reflectionParameter,
        MapRule $mapRule,
    ): SourceMember {
        $selector = $mapRule->selector();
        $kind     = $mapRule->selectsGetter() ? 'getter' : 'method';

        if (! $source->hasMethod($selector)) {
            throw new MappingCompilationFailed($this->message(
                $source,
                $target,
                $reflectionParameter->getName(),
                sprintf('Configured %s selector %s() does not exist.', $kind, $selector),
            ));
        }

        $reflectionMethod = $source->getMethod($selector);
        if (! $reflectionMethod->isPublic() || $reflectionMethod->isStatic() || 0 !== $reflectionMethod->getNumberOfParameters() || ! $reflectionMethod->getReturnType() instanceof ReflectionType) {
            throw new MappingCompilationFailed($this->message(
                $source,
                $target,
                $reflectionParameter->getName(),
                sprintf('Configured %s selector %s() must select a public, non-static, zero-argument method with a declared return type.', $kind, $selector),
            ));
        }

        return new SourceMember(
            $selector,
            'method',
            $reflectionMethod->getReturnType(),
            $this->sourceMemberContext($reflectionMethod->getReturnType(), $reflectionMethod->getDeclaringClass(), $source),
            $mapRule->selectsGetter() ? 'getter_rule' : 'method_rule',
        );
    }

    /**
     * @param ReflectionClass<object> $source
     * @param ReflectionClass<object> $target
     */
    private function resolveTransformer(
        ReflectionClass $source,
        ReflectionClass $target,
        ReflectionParameter $reflectionParameter,
        MapRule $mapRule,
    ): TransformerMetadata {
        $transformerClass = $mapRule->transformer();
        if (null === $transformerClass) {
            throw new LogicException('A transformer rule must provide a transformer class.');
        }

        try {
            $transformer = $this->valueTransformerRegistry->get($transformerClass);
        } catch (Throwable) {
            throw new MappingCompilationFailed($this->message(
                $source,
                $target,
                $reflectionParameter->getName(),
                sprintf('Configured transformer %s is not registered.', $transformerClass),
            ));
        }

        if ($transformer::class !== $transformerClass) {
            throw new MappingCompilationFailed($this->message(
                $source,
                $target,
                $reflectionParameter->getName(),
                sprintf('Configured transformer %s resolved to the wrong class.', $transformerClass),
            ));
        }

        $reflectionClass = $this->reflectObject($transformerClass);
        if (! $reflectionClass->hasMethod('transform')) {
            throw new MappingCompilationFailed($this->message(
                $source,
                $target,
                $reflectionParameter->getName(),
                sprintf('Configured transformer %s must define transform().', $transformerClass),
            ));
        }

        $reflectionMethod     = $reflectionClass->getMethod('transform');
        $parameters           = $reflectionMethod->getParameters();
        $parameter            = $parameters[0] ?? null;
        $returnType           = $reflectionMethod->getReturnType();
        if (! $this->isValidTransformerMethod($reflectionMethod)
            || null === $parameter
            || $parameter->isPassedByReference()
            || ! $parameter->getType() instanceof ReflectionType
            || ! $returnType instanceof ReflectionType) {
            throw new MappingCompilationFailed($this->message(
                $source,
                $target,
                $reflectionParameter->getName(),
                sprintf('Configured transformer %s must expose a public, non-static, non-variadic transform() method with exactly one required typed by-value parameter and a typed non-void, non-never return.', $transformerClass),
            ));
        }

        return new TransformerMetadata(
            $transformerClass,
            $parameter->getType(),
            $this->sourceMemberContext($parameter->getType(), $reflectionMethod->getDeclaringClass(), $reflectionClass),
            $returnType,
            $this->sourceMemberContext($returnType, $reflectionMethod->getDeclaringClass(), $reflectionClass),
            $this->hashFile($reflectionMethod->getFileName()),
        );
    }

    private function isValidTransformerMethod(ReflectionMethod $reflectionMethod): bool
    {
        $returnType = $reflectionMethod->getReturnType();

        return $reflectionMethod->isPublic()
            && ! $reflectionMethod->isStatic()
            && ! $reflectionMethod->isVariadic()
            && 1 === $reflectionMethod->getNumberOfParameters()
            && 1 === $reflectionMethod->getNumberOfRequiredParameters()
            && $returnType instanceof ReflectionType
            && (! $returnType instanceof ReflectionNamedType || ! in_array($returnType->getName(), ['void', 'never'], true));
    }

    /**
     * @param ReflectionClass<object> $source
     * @param ReflectionClass<object> $target
     * @param ReflectionClass<object> $targetContext
     */
    private function assertTypesAreCompatible(
        ReflectionClass $source,
        ReflectionClass $target,
        ReflectionParameter $reflectionParameter,
        ?MapRule $mapRule,
        SourceMember $sourceMember,
        ReflectionType $reflectionType,
        ReflectionClass $targetContext,
        ?TransformerMetadata $transformerMetadata,
    ): void {
        if (! $transformerMetadata instanceof TransformerMetadata) {
            if (! $this->typeCompatibilityChecker->isCompatible(
                $sourceMember->type,
                $sourceMember->declaringClass,
                $reflectionType,
                $targetContext,
            )) {
                throw new MappingCompilationFailed($this->message(
                    $source,
                    $target,
                    $reflectionParameter->getName(),
                    sprintf(
                        '%sSource type %s is not assignable to target type %s.',
                        $mapRule instanceof MapRule ? sprintf('Configured selector %s: ', $this->describeRule($mapRule)) : '',
                        $this->typeCompatibilityChecker->describe($sourceMember->type, $sourceMember->declaringClass),
                        $this->typeCompatibilityChecker->describe($reflectionType, $targetContext),
                    ),
                ));
            }

            return;
        }

        if (! $this->typeCompatibilityChecker->isCompatible(
            $sourceMember->type,
            $sourceMember->declaringClass,
            $transformerMetadata->inputType,
            $transformerMetadata->inputDeclaringClass,
        )) {
            throw new MappingCompilationFailed($this->message(
                $source,
                $target,
                $reflectionParameter->getName(),
                sprintf(
                    'Configured selector %s and transformer %s are incompatible: source type %s is not assignable to transformer input type %s.',
                    $mapRule instanceof MapRule ? $this->describeRule($mapRule) : 'convention',
                    $transformerMetadata->class,
                    $this->typeCompatibilityChecker->describe($sourceMember->type, $sourceMember->declaringClass),
                    $this->typeCompatibilityChecker->describe($transformerMetadata->inputType, $transformerMetadata->inputDeclaringClass),
                ),
            ));
        }

        if (! $this->typeCompatibilityChecker->isCompatible(
            $transformerMetadata->outputType,
            $transformerMetadata->outputDeclaringClass,
            $reflectionType,
            $targetContext,
        )) {
            throw new MappingCompilationFailed($this->message(
                $source,
                $target,
                $reflectionParameter->getName(),
                sprintf(
                    'Configured transformer %s output type %s is not assignable to target type %s.',
                    $transformerMetadata->class,
                    $this->typeCompatibilityChecker->describe($transformerMetadata->outputType, $transformerMetadata->outputDeclaringClass),
                    $this->typeCompatibilityChecker->describe($reflectionType, $targetContext),
                ),
            ));
        }
    }

    /**
     * @param ReflectionClass<object> $source
     * @param ReflectionClass<object> $target
     * @param ReflectionClass<object> $targetContext
     */
    private function resolveNestedMapping(
        ReflectionClass $source,
        ReflectionClass $target,
        ReflectionParameter $reflectionParameter,
        MapRule $mapRule,
        SourceMember $sourceMember,
        ReflectionType $reflectionType,
        ReflectionClass $targetContext,
    ): NestedMappingMetadata {
        if ($mapRule->isNested()) {
            $nestedTarget = $mapRule->nestedTarget();
            if (null === $nestedTarget) {
                throw new LogicException('A nested mapping rule must provide its target class.');
            }

            $sourceType      = $this->structuralNamedTypeForParameter($source, $target, $reflectionParameter, $mapRule, $sourceMember->type, $sourceMember->declaringClass);
            $targetNamedType = $this->structuralNamedTypeForParameter($source, $target, $reflectionParameter, $mapRule, $reflectionType, $targetContext);
            $dependency      = $this->resolveDependency($source, $target, $reflectionParameter, $mapRule, $sourceType['name'], $nestedTarget);

            if ($sourceType['nullable'] !== $targetNamedType['nullable']) {
                throw new MappingCompilationFailed($this->message(
                    $source,
                    $target,
                    $reflectionParameter->getName(),
                    sprintf('Configured nested selector %s has incompatible nullability.', $this->describeRule($mapRule)),
                ));
            }

            if (! is_a($nestedTarget, $targetNamedType['name'], true)) {
                throw new MappingCompilationFailed($this->message(
                    $source,
                    $target,
                    $reflectionParameter->getName(),
                    sprintf('Configured nested selector %s resolves to %s, which is not assignable to target type %s.', $this->describeRule($mapRule), $nestedTarget, $this->typeCompatibilityChecker->describe($reflectionType, $targetContext)),
                ));
            }

            $dependencyFingerprint = $this->dependencyIdentity($dependency, $this->message($source, $target, $reflectionParameter->getName(), 'Configured nested mapping'));

            return $this->registerNestedDependency(
                new NestedMappingMetadata(
                    'nested',
                    $dependency->source(),
                    $dependency->target(),
                    $sourceType['nullable'],
                    $dependencyFingerprint,
                ),
                $dependency,
            );
        }

        $elementSource = $mapRule->collectionElementSource();
        $elementTarget = $mapRule->collectionElementTarget();
        if (null === $elementSource || null === $elementTarget) {
            throw new LogicException('A collection mapping rule must provide both element classes.');
        }

        $sourceType      = $this->arrayStructuralTypeForParameter($source, $target, $reflectionParameter, $mapRule, $sourceMember->type, $sourceMember->declaringClass);
        $targetArrayType = $this->arrayStructuralTypeForParameter($source, $target, $reflectionParameter, $mapRule, $reflectionType, $targetContext);
        if ($sourceType['nullable'] !== $targetArrayType['nullable']) {
            throw new MappingCompilationFailed($this->message(
                $source,
                $target,
                $reflectionParameter->getName(),
                sprintf('Configured collection selector %s has incompatible nullability.', $this->describeRule($mapRule)),
            ));
        }

        $dependency = $this->resolveDependency($source, $target, $reflectionParameter, $mapRule, $elementSource, $elementTarget);

        $dependencyFingerprint = $this->dependencyIdentity($dependency, $this->message($source, $target, $reflectionParameter->getName(), 'Configured collection mapping'));

        return $this->registerNestedDependency(
            new NestedMappingMetadata(
                'collection',
                $dependency->source(),
                $dependency->target(),
                $sourceType['nullable'],
                $dependencyFingerprint,
                $elementSource,
                $elementTarget,
            ),
            $dependency,
        );
    }

    /**
     * @param ReflectionClass<object> $reflectionClass
     *
     * @return array{name: class-string, nullable: bool}
     */
    private function structuralNamedType(ReflectionType $reflectionType, ReflectionClass $reflectionClass): array
    {
        if (! $reflectionType instanceof ReflectionNamedType || $reflectionType->isBuiltin()) {
            throw new MappingCompilationFailed(sprintf('Structural mapping requires a concrete named class type; got %s.', $this->typeCompatibilityChecker->describe($reflectionType, $reflectionClass)));
        }

        $name  = $this->resolveNamedClass($reflectionType, $reflectionClass);
        $class = new ReflectionClass($name);
        if ($class->isInterface() || $class->isAbstract() || $class->isAnonymous()) {
            throw new MappingCompilationFailed(sprintf('Structural mapping requires a concrete named class type; got %s.', $name));
        }

        return [
            'name'     => $name,
            'nullable' => $reflectionType->allowsNull(),
        ];
    }

    /**
     * @param ReflectionClass<object> $source
     * @param ReflectionClass<object> $target
     * @param ReflectionClass<object> $context
     *
     * @return array{name: class-string, nullable: bool}
     */
    private function structuralNamedTypeForParameter(ReflectionClass $source, ReflectionClass $target, ReflectionParameter $reflectionParameter, MapRule $mapRule, ReflectionType $reflectionType, ReflectionClass $context): array
    {
        try {
            return $this->structuralNamedType($reflectionType, $context);
        } catch (MappingCompilationFailed $exception) {
            throw new MappingCompilationFailed($this->message($source, $target, $reflectionParameter->getName(), sprintf('Configured %s selector %s: %s', $mapRule->operation(), $this->describeRule($mapRule), $exception->getMessage())), $exception->getCode(), $exception);
        }
    }

    /**
     * @param ReflectionClass<object> $reflectionClass
     *
     * @return array{nullable: bool}
     */
    private function arrayStructuralType(ReflectionType $reflectionType, ReflectionClass $reflectionClass): array
    {
        if (! $reflectionType instanceof ReflectionNamedType || 'array' !== $reflectionType->getName()) {
            throw new MappingCompilationFailed(sprintf('Structural collection mapping requires array or ?array; got %s.', $this->typeCompatibilityChecker->describe($reflectionType, $reflectionClass)));
        }

        return [
            'nullable' => $reflectionType->allowsNull(),
        ];
    }

    /**
     * @param ReflectionClass<object> $source
     * @param ReflectionClass<object> $target
     * @param ReflectionClass<object> $context
     *
     * @return array{nullable: bool}
     */
    private function arrayStructuralTypeForParameter(ReflectionClass $source, ReflectionClass $target, ReflectionParameter $reflectionParameter, MapRule $mapRule, ReflectionType $reflectionType, ReflectionClass $context): array
    {
        try {
            return $this->arrayStructuralType($reflectionType, $context);
        } catch (MappingCompilationFailed $exception) {
            throw new MappingCompilationFailed($this->message($source, $target, $reflectionParameter->getName(), sprintf('Configured collection selector %s: %s', $this->describeRule($mapRule), $exception->getMessage())), $exception->getCode(), $exception);
        }
    }

    /**
     * @param ReflectionClass<object> $source
     * @param ReflectionClass<object> $target
     * @param class-string            $dependencySource
     * @param class-string            $dependencyTarget
     */
    private function resolveDependency(ReflectionClass $source, ReflectionClass $target, ReflectionParameter $reflectionParameter, MapRule $mapRule, string $dependencySource, string $dependencyTarget): MappingDefinitionInterface
    {
        return $this->snapshotDependency(
            $dependencySource,
            $dependencyTarget,
            $this->message($source, $target, $reflectionParameter->getName(), sprintf('Configured %s selector %s requires registered mapping %s -> %s.', $mapRule->operation(), $this->describeRule($mapRule), $dependencySource, $dependencyTarget)),
            $this->message($source, $target, $reflectionParameter->getName(), sprintf('Configured %s selector %s resolved the wrong mapping pair.', $mapRule->operation(), $this->describeRule($mapRule))),
        );
    }

    /**
     * @param ReflectionClass<object> $reflectionClass
     *
     * @return class-string
     */
    private function resolveNamedClass(ReflectionNamedType $reflectionNamedType, ReflectionClass $reflectionClass): string
    {
        $name = match ($reflectionNamedType->getName()) {
            'self', 'static' => $reflectionClass->getName(),
            'parent'         => ($parent = $reflectionClass->getParentClass()) instanceof ReflectionClass ? $parent->getName() : throw new LogicException(sprintf('%s has no parent class.', $reflectionClass->getName())),
            default          => $reflectionNamedType->getName(),
        };

        if (! class_exists($name)) {
            throw new MappingCompilationFailed(sprintf('Structural mapping requires a concrete named class type; got %s.', $name));
        }

        return $name;
    }

    private function dependencyIdentity(MappingDefinitionInterface $mappingDefinition, string $cycleContext): string
    {
        $key        = $mappingDefinition->key();
        $cycleStart = array_search($key, $this->dependencyStack, true);
        if (false !== $cycleStart) {
            $cycle = [...array_slice($this->dependencyStack, $cycleStart), $key];

            throw new MappingCompilationFailed(sprintf('%s: mapping dependency cycle detected: %s.', $cycleContext, implode(' -> ', $cycle)));
        }

        if (isset($this->dependencyFingerprints[$key])) {
            return $this->dependencyFingerprints[$key];
        }

        $this->dependencyStack[] = $key;

        try {
            $this->dependencyDefinitions[$key] = $mappingDefinition;
            $source                            = new ReflectionClass($mappingDefinition->source());
            $target                            = new ReflectionClass($mappingDefinition->target());
            $identity                          = [
                'pair'           => $key,
                'kind'           => $mappingDefinition instanceof ProviderCustomMappingDefinition ? 'provider_custom' : ($mappingDefinition instanceof CustomMappingDefinition ? 'custom' : 'conventional'),
                'sourceFileHash' => $this->fileHash($source),
                'targetFileHash' => $this->fileHash($target),
            ];

            if ($mappingDefinition instanceof ProviderCustomMappingDefinition) {
                $identity['mapperId'] = $mappingDefinition->mapperId();
            } elseif ($mappingDefinition instanceof CustomMappingDefinition) {
                $mapper                              = new ReflectionClass($mappingDefinition->mapper);
                $identity['mapperClass']             = $mapper->getName();
                $identity['mapperFileHash']          = $this->hashFile($mapper->getFileName());
                $identity['mapperMapMethodFileHash'] = $this->hashFile($mapper->getMethod('map')->getFileName());
            } elseif ($mappingDefinition instanceof MappingDefinition) {
                $identity['ignoredSource'] = $mappingDefinition->ignoredSource;
                $rules                     = $mappingDefinition->rules;
                ksort($rules);
                $identity['rules'] = [];
                foreach ($rules as $parameter => $rule) {
                    $ruleIdentity = [
                        'parameter'               => $parameter,
                        'selectorKind'            => $rule->selectsProperty() ? 'property' : ($rule->selectsGetter() ? 'getter' : 'method'),
                        'selector'                => $rule->selector(),
                        'operation'               => $rule->operation(),
                        'transformer'             => $rule->transformer(),
                        'transformerFileHash'     => $this->transformerFileHash($rule),
                        'nestedTarget'            => $rule->nestedTarget(),
                        'collectionElementSource' => $rule->collectionElementSource(),
                        'collectionElementTarget' => $rule->collectionElementTarget(),
                    ];
                    if ($rule->isNested()) {
                        $ruleIdentity['dependency'] = $this->dependencyIdentity(
                            $this->structuralRuleDependency($source, $target, $parameter, $rule),
                            $cycleContext,
                        );
                    } elseif ($rule->isCollection()) {
                        $ruleIdentity['dependency'] = $this->dependencyIdentity(
                            $this->structuralRuleDependency($source, $target, $parameter, $rule),
                            $cycleContext,
                        );
                    }
                    $identity['rules'][] = $ruleIdentity;
                }
            } else {
                throw new MappingCompilationFailed(sprintf('%s: registered mapping %s has an unsupported definition kind.', $cycleContext, $key));
            }

            $fingerprint                        = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
            $this->dependencyFingerprints[$key] = $fingerprint;

            return $fingerprint;
        } finally {
            array_pop($this->dependencyStack);
        }
    }

    private function transformerFileHash(MapRule $mapRule): ?string
    {
        $transformerClass = $mapRule->transformer();
        if (null === $transformerClass) {
            return null;
        }

        $reflectionClass = new ReflectionClass($transformerClass);
        if (! $reflectionClass->hasMethod('transform')) {
            return null;
        }

        return $this->hashFile($reflectionClass->getMethod('transform')->getFileName());
    }

    /**
     * @param ReflectionClass<object> $source
     * @param ReflectionClass<object> $target
     */
    private function structuralRuleDependency(ReflectionClass $source, ReflectionClass $target, string $parameterName, MapRule $mapRule): MappingDefinitionInterface
    {
        $parameter   = null;
        $constructor = $target->getConstructor();
        foreach ($constructor?->getParameters() ?? [] as $candidate) {
            if ($candidate->getName() === $parameterName) {
                $parameter = $candidate;

                break;
            }
        }

        if (! $parameter instanceof ReflectionParameter) {
            throw new MappingCompilationFailed($this->message($source, $target, $parameterName, 'Configured structural rule does not refer to a target constructor parameter.'));
        }

        $member = $this->findSourceMember($source, $target, $parameter, $mapRule);
        if (! $member instanceof SourceMember) {
            throw new MappingCompilationFailed($this->message($source, $target, $parameterName, 'Configured structural selector has no safe readable source member.'));
        }

        if ($mapRule->isNested()) {
            $sourceClass = $this->structuralNamedType($member->type, $member->declaringClass)['name'];
            $targetClass = $mapRule->nestedTarget();
            if (null === $targetClass) {
                throw new LogicException('A nested mapping rule must provide a target class.');
            }

            return $this->lookupDependency($sourceClass, $targetClass, $this->message($source, $target, $parameterName, 'Configured nested mapping'));
        }

        $elementSource = $mapRule->collectionElementSource();
        $elementTarget = $mapRule->collectionElementTarget();
        if (null === $elementSource || null === $elementTarget) {
            throw new LogicException('A collection mapping rule must provide both element classes.');
        }

        return $this->lookupDependency($elementSource, $elementTarget, $this->message($source, $target, $parameterName, 'Configured collection mapping'));
    }

    /**
     * @param class-string $source
     * @param class-string $target
     */
    private function lookupDependency(string $source, string $target, string $context): MappingDefinitionInterface
    {
        return $this->snapshotDependency(
            $source,
            $target,
            sprintf('%s: nested dependency %s -> %s is not registered.', $context, $source, $target),
            sprintf('%s: nested dependency %s -> %s resolved the wrong mapping pair.', $context, $source, $target),
        );
    }

    /**
     * @param class-string $source
     * @param class-string $target
     */
    private function snapshotDependency(string $source, string $target, string $notRegisteredMessage, string $wrongPairMessage): MappingDefinitionInterface
    {
        $key = $source . '->' . $target;
        if (isset($this->dependencyDefinitions[$key])) {
            return $this->dependencyDefinitions[$key];
        }

        if (! $this->mappingRegistry instanceof MappingRegistryInterface) {
            throw new MappingCompilationFailed($notRegisteredMessage);
        }

        try {
            $mappingDefinition = $this->mappingRegistry->get($source, $target);
            if (! $mappingDefinition instanceof MappingDefinition
                && ! $mappingDefinition instanceof CustomMappingDefinition
                && ! $mappingDefinition instanceof ProviderCustomMappingDefinition
            ) {
                throw new LogicException('The mapping registry returned an unsupported definition type.');
            }

            $actualSource = $mappingDefinition->source();
            $actualTarget = $mappingDefinition->target();
        } catch (MappingCompilationFailed $exception) {
            if ($this->isReentrantCompilationFailure($exception)) {
                throw $exception;
            }

            throw $this->unregisteredDependencyFailure($notRegisteredMessage);
        } catch (Throwable) {
            throw $this->unregisteredDependencyFailure($notRegisteredMessage);
        }

        if ($actualSource !== $source || $actualTarget !== $target) {
            throw new MappingCompilationFailed($wrongPairMessage);
        }

        $this->dependencyDefinitions[$key] = $mappingDefinition;

        return $mappingDefinition;
    }

    private function isReentrantCompilationFailure(MappingCompilationFailed $mappingCompilationFailed): bool
    {
        if (! isset($this->reentrantCompilationFailures[$mappingCompilationFailed])) {
            return false;
        }

        unset($this->reentrantCompilationFailures[$mappingCompilationFailed]);

        return true;
    }

    private function unregisteredDependencyFailure(string $message): MappingCompilationFailed
    {
        return new MappingCompilationFailed($message);
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
        return $this->hashFile($reflectionClass->getFileName());
    }

    private function hashFile(false|string $file): ?string
    {
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

    /**
     * @param class-string<object> $class
     *
     * @return ReflectionClass<object>
     */
    private function reflectObject(string $class): ReflectionClass
    {
        return new ReflectionClass($class);
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
