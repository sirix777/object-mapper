<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Generator;

use Sirix\ObjectMapper\Metadata\MappingMetadata;

use function hash;
use function implode;
use function json_encode;
use function ltrim;
use function sprintf;

/** @internal */
final class PhpMapperGenerator
{
    private const FORMAT_VERSION = '3';

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
        $arguments = [];
        foreach ($mappingMetadata->parameters as $parameter) {
            if (null === $parameter->sourceMember) {
                continue;
            }

            $expression = $parameter->sourceMember->expression('$source');
            if (null !== $parameter->transformer) {
                $expression = sprintf(
                    '$this->transformers->get(\%s::class)->transform(%s)',
                    ltrim($parameter->transformer->class, '\\'),
                    $expression,
                );
            }

            $arguments[] = sprintf(
                '            %s: %s,',
                $parameter->name,
                $expression,
            );
        }

        $source = '\\' . ltrim($mappingMetadata->source, '\\');
        $target = '\\' . ltrim($mappingMetadata->target, '\\');
        $class  = 'Mapper_' . $cacheKey;

        return "<?php\n\n"
            . "declare(strict_types=1);\n\n"
            . "namespace Sirix\\ObjectMapper\\Generated;\n\n"
            . "final class {$class} implements \\Sirix\\ObjectMapper\\Generator\\GeneratedMapperInterface\n"
            . "{\n"
            . "    public function __construct(\n"
            . "        private \\Sirix\\ObjectMapper\\Contract\\ValueTransformerRegistryInterface \$transformers,\n"
            . "    ) {}\n\n"
            . "    public function map(object \$source): object\n"
            . "    {\n"
            . "        if (!\$source instanceof {$source}) {\n"
            . "            throw new \\InvalidArgumentException('Expected an instance of {$source}.');\n"
            . "        }\n\n"
            . "        return new {$target}(\n"
            . implode("\n", $arguments)
            . "\n        );\n"
            . "    }\n"
            . "}\n";
    }

    /** @return array<string, mixed> */
    private function normalizedMetadata(MappingMetadata $mappingMetadata): array
    {
        $parameters = [];
        foreach ($mappingMetadata->parameters as $parameter) {
            $typeExporter = new ReflectionTypeExporter();
            $parameters[] = [
                'name'        => $parameter->name,
                'hasDefault'  => $parameter->hasDefault,
                'type'        => null === $parameter->type
                    ? null
                    : $typeExporter->export($parameter->type, $parameter->declaringClass),
                'source'      => null === $parameter->sourceMember ? null : [
                    'kind'      => $parameter->sourceMember->kind,
                    'name'      => $parameter->sourceMember->name,
                    'selection' => $parameter->sourceMember->selection,
                    'type'      => $typeExporter->export(
                        $parameter->sourceMember->type,
                        $parameter->sourceMember->declaringClass,
                    ),
                ],
                'transformer' => null === $parameter->transformer ? null : [
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
            ];
        }

        return [
            'format'         => self::FORMAT_VERSION,
            'source'         => $mappingMetadata->source,
            'target'         => $mappingMetadata->target,
            'sourceFileHash' => $mappingMetadata->sourceFileHash,
            'targetFileHash' => $mappingMetadata->targetFileHash,
            'parameters'     => $parameters,
        ];
    }
}
