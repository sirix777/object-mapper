<?php

declare(strict_types=1);

use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Generator\MapperCache;
use Sirix\ObjectMapper\Generator\PhpMapperGenerator;
use Sirix\ObjectMapper\Metadata\MappingMetadataFactory;
use Sirix\ObjectMapper\Runtime\MappingRegistry;
use Sirix\ObjectMapper\Runtime\ObjectMapper;
use Sirix\ObjectMapper\Runtime\ValueTransformerRegistry;

\ini_set('display_errors', 'stderr');

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

final class CacheWarmupSource
{
    public function __construct(public int $id) {}
}

final class CacheWarmupTarget
{
    public function __construct(public int $id) {}
}

$cacheDirectory = $argv[1] ?? '';
$barrierPath    = $argv[2] ?? '';

if ('' === $cacheDirectory) {
    \fwrite(STDERR, "cacheDirectory argument is required\n");

    exit(2);
}

if ('' !== $barrierPath) {
    \file_put_contents($barrierPath . '.ready', "ready\n", FILE_APPEND);

    $barrierDeadline = \microtime(true) + 10.0;
    while (! \is_file($barrierPath)) {
        if (\microtime(true) > $barrierDeadline) {
            \fwrite(STDERR, "barrier timeout\n");

            exit(3);
        }

        \usleep(1000);
    }
}

try {
    $mappingRegistry          = new MappingRegistry([new MappingDefinition(CacheWarmupSource::class, CacheWarmupTarget::class)]);
    $valueTransformerRegistry = new ValueTransformerRegistry();
    $objectMapper             = new ObjectMapper(
        $mappingRegistry,
        new MapperCache(
            new MappingMetadataFactory($valueTransformerRegistry, mappingRegistry: $mappingRegistry),
            new PhpMapperGenerator(),
            $cacheDirectory,
            $valueTransformerRegistry,
            true,
            $mappingRegistry,
        ),
    );

    $objectMapper->warmup();
    $target = $objectMapper->map(new CacheWarmupSource(7), CacheWarmupTarget::class);

    echo \json_encode([
        'id' => $target->id,
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $throwable) {
    \fwrite(STDERR, $throwable::class . ': ' . $throwable->getMessage() . PHP_EOL);

    exit(1);
}
