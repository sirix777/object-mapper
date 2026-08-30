<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Generator;

use LogicException;

use ReflectionClass;
use Sirix\ObjectMapper\Metadata\MappingMetadata;

use function addslashes;
use function class_exists;
use function hash;
use function implode;
use function json_encode;
use function preg_match;
use function sprintf;
use function str_replace;

/** @internal */
final class PhpMapperGenerator
{
    private const FORMAT_VERSION = '6';

    public function cacheKey(MappingMetadata $mappingMetadata): string
    {
        return hash('sha256', json_encode($this->normalizedMetadata($mappingMetadata), JSON_THROW_ON_ERROR));
    }

    public function className(string $cacheKey): string
    {
        return 'Sirix\ObjectMapper\Generated\Mapper_' . $cacheKey;
    }

    public function generate(MappingMetadata $mappingMetadata, string $cacheKey): string
    {
        $arguments              = [];
        $collectionMethods      = [];
        $statements             = [];
        $constantValueExporter  = new ConstantValueExporter();
        foreach ($mappingMetadata->parameters as $index => $parameter) {
            if (null !== $parameter->constant) {
                $arguments[] = sprintf(
                    '            %s: %s,',
                    $parameter->name,
                    $constantValueExporter->export($parameter->constant),
                );

                continue;
            }

            if (null === $parameter->sourceMember) {
                continue;
            }

            $expression = $parameter->sourceMember->expression('$source');
            if (null !== $parameter->transformer) {
                $expression = sprintf(
                    '$this->transformers->get(%s::class)->transform(%s)',
                    $this->classToken($parameter->transformer->class),
                    $expression,
                );
            }

            if (null !== $parameter->nestedMapping) {
                $nested           = $parameter->nestedMapping;
                $dependencySource = $this->classToken($nested->source);
                $dependencyTarget = $this->classToken($nested->target);
                $sourceMatch      = '\Sirix\ObjectMapper\Definition\SourceMatchMode::' . $nested->sourceMatch->name;

                if ('nested' === $nested->operation) {
                    $dispatch = sprintf(
                        '$this->nestedMappings->mapNested(%s, %s::class, %s::class, %s)',
                        $expression,
                        $dependencySource,
                        $dependencyTarget,
                        $sourceMatch,
                    );
                    if ($nested->nullable) {
                        $temporary    = '$nestedValue' . $index;
                        $statements[] = sprintf('        %s = %s;', $temporary, $expression);
                        $expression   = sprintf('null === %s ? null : %s', $temporary, str_replace($expression, $temporary, $dispatch));
                    } else {
                        $expression = $dispatch;
                    }
                } else {
                    $method              = 'mapCollectionForParameter' . $index;
                    $elementSource       = $this->classToken((string) $nested->elementSource);
                    $collectionMethods[] = $this->collectionMethod(
                        $method,
                        $mappingMetadata->source,
                        $mappingMetadata->target,
                        $parameter->name,
                        $elementSource,
                        $dependencyTarget,
                        $sourceMatch,
                    );
                    $mapped     = sprintf('$this->%s(%s)', $method, $expression);
                    if ($nested->nullable) {
                        $temporary    = '$collectionValue' . $index;
                        $statements[] = sprintf('        %s = %s;', $temporary, $expression);
                        $expression   = sprintf('null === %s ? null : %s', $temporary, str_replace($expression, $temporary, $mapped));
                    } else {
                        $expression = $mapped;
                    }
                }
            }

            $arguments[] = sprintf(
                '            %s: %s,',
                $parameter->name,
                $expression,
            );
        }

        $source      = $this->classToken($mappingMetadata->source);
        $target      = $this->classToken($mappingMetadata->target);
        $class       = 'Mapper_' . $cacheKey;
        $sourceMatch = '\Sirix\ObjectMapper\Definition\SourceMatchMode::' . $mappingMetadata->sourceMatch->name;

        return "<?php\n\n"
            . "declare(strict_types=1);\n\n"
            . "namespace Sirix\\ObjectMapper\\Generated;\n\n"
            . "final class {$class} implements \\Sirix\\ObjectMapper\\Generator\\GeneratedMapperInterface\n"
            . "{\n"
            . "    public function __construct(\n"
            . "        private \\Sirix\\ObjectMapper\\Contract\\ValueTransformerRegistryInterface \$transformers,\n"
            . "        private \\Sirix\\ObjectMapper\\Runtime\\NestedMappingRuntimeInterface \$nestedMappings,\n"
            . "    ) {}\n\n"
            . "    public function map(object \$source): object\n"
            . "    {\n"
            . "        if (!\\Sirix\\ObjectMapper\\Runtime\\SourceMatcher::matches(\$source, {$source}::class, {$sourceMatch})) {\n"
            . "            throw new \\InvalidArgumentException('Expected an instance of {$source}.');\n"
            . "        }\n\n"
            . implode("\n", $statements)
            . ([] === $statements ? '' : "\n")
            . "        return new {$target}(\n"
            . implode("\n", $arguments)
            . "\n        );\n"
            . "    }\n"
            . implode("\n", $collectionMethods)
            . "}\n";
    }

    private function collectionMethod(string $method, string $source, string $target, string $parameter, string $elementSource, string $elementTarget, string $sourceMatch): string
    {
        $parameter   = addslashes($parameter);
        $sourceClass = $this->classToken($source);
        $targetClass = $this->classToken($target);

        return "\n    /** @return list<object> */\n"
            . "    private function {$method}(array \$values): array\n"
            . "    {\n"
            . "        \$mapped = [];\n"
            . "        foreach (\$values as \$key => \$element) {\n"
            . "            if (!is_object(\$element) || !\\Sirix\\ObjectMapper\\Runtime\\SourceMatcher::matches(\$element, {$elementSource}::class, {$sourceMatch})) {\n"
            . "                \$this->nestedMappings->collectionElementTypeFailure(\n"
            . "                    {$sourceClass}::class,\n"
            . "                    {$targetClass}::class,\n"
            . "                    '{$parameter}',\n"
            . "                    \$key,\n"
            . "                    {$elementSource}::class,\n"
            . "                    \$element,\n"
            . "                );\n"
            . "            }\n"
            . "\n"
            . "            \$mapped[] = \$this->nestedMappings->mapNested(\$element, {$elementSource}::class, {$elementTarget}::class, {$sourceMatch});\n"
            . "        }\n"
            . "\n"
            . "        return \$mapped;\n"
            . "    }\n";
    }

    private function classToken(string $class): string
    {
        if (1 !== preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\\\[a-zA-Z_][a-zA-Z0-9_]*)*$/D', $class)
            || ! class_exists($class)) {
            throw new LogicException(sprintf('Cannot generate a mapper with unsafe class name "%s".', $class));
        }

        $reflectionClass = new ReflectionClass($class);
        if ($reflectionClass->isAnonymous() || $reflectionClass->getName() !== $class) {
            throw new LogicException(sprintf('Cannot generate a mapper with non-canonical class name "%s".', $class));
        }

        return '\\' . $class;
    }

    /** @return array<string, mixed> */
    private function normalizedMetadata(MappingMetadata $mappingMetadata): array
    {
        $parameters = [];
        foreach ($mappingMetadata->parameters as $parameter) {
            $typeExporter = new ReflectionTypeExporter();
            $parameters[] = [
                'name'          => $parameter->name,
                'hasDefault'    => $parameter->hasDefault,
                'type'          => null === $parameter->type
                    ? null
                    : $typeExporter->export($parameter->type, $parameter->declaringClass),
                'source'        => null === $parameter->sourceMember ? null : [
                    'kind'      => $parameter->sourceMember->kind,
                    'name'      => $parameter->sourceMember->name,
                    'selection' => $parameter->sourceMember->selection,
                    'type'      => $typeExporter->export(
                        $parameter->sourceMember->type,
                        $parameter->sourceMember->declaringClass,
                    ),
                ],
                'transformer'   => null === $parameter->transformer ? null : [
                    'class'         => $parameter->transformer->class,
                    'inputType'     => $typeExporter->export(
                        $parameter->transformer->inputType,
                        $parameter->transformer->inputDeclaringClass,
                    ),
                    'outputType'    => $typeExporter->export(
                        $parameter->transformer->outputType,
                        $parameter->transformer->outputDeclaringClass,
                    ),
                    'classFileHash' => $parameter->transformer->fileHash,
                ],
                'nestedMapping' => null === $parameter->nestedMapping ? null : [
                    'operation'             => $parameter->nestedMapping->operation,
                    'nullable'              => $parameter->nestedMapping->nullable,
                    'source'                => $parameter->nestedMapping->source,
                    'target'                => $parameter->nestedMapping->target,
                    'elementSource'         => $parameter->nestedMapping->elementSource,
                    'elementTarget'         => $parameter->nestedMapping->elementTarget,
                    'sourceMatch'           => $parameter->nestedMapping->sourceMatch->name,
                    'dependencyFingerprint' => $parameter->nestedMapping->dependencyFingerprint,
                ],
                'constant'      => null === $parameter->constant ? null : $parameter->constant->identity(),
            ];
        }

        return [
            'format'         => self::FORMAT_VERSION,
            'source'         => $mappingMetadata->source,
            'target'         => $mappingMetadata->target,
            'sourceMatch'    => $mappingMetadata->sourceMatch->name,
            'sourceFileHash' => $mappingMetadata->sourceFileHash,
            'targetFileHash' => $mappingMetadata->targetFileHash,
            'parameters'     => $parameters,
        ];
    }
}
