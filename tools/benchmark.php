<?php

declare(strict_types=1);

use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Definition\MapRule;
use Sirix\ObjectMapper\Generator\MapperCache;
use Sirix\ObjectMapper\Generator\PhpMapperGenerator;
use Sirix\ObjectMapper\Metadata\MappingMetadataFactory;
use Sirix\ObjectMapper\Runtime\MappingRegistry;
use Sirix\ObjectMapper\Runtime\ObjectMapper;
use Sirix\ObjectMapper\Runtime\ValueTransformerRegistry;

require \dirname(__DIR__) . '/vendor/autoload.php';

const SIMPLE_ITERATIONS     = 20_000;
const NESTED_ITERATIONS     = 10_000;
const COLLECTION_ITERATIONS = 100;
const COLLECTION_SIZE       = 100;
const ROUNDS                = 5;

final readonly class BenchmarkSimpleSource
{
    public function __construct(public int $id, public string $name, public bool $active) {}
}

final readonly class BenchmarkSimpleTarget
{
    public function __construct(public int $id, public string $name, public bool $active) {}
}

final readonly class BenchmarkChildSource
{
    public function __construct(public string $value) {}
}

final readonly class BenchmarkChildTarget
{
    public function __construct(public string $value) {}
}

final readonly class BenchmarkNestedSource
{
    public function __construct(public BenchmarkChildSource $child) {}
}

final readonly class BenchmarkNestedTarget
{
    public function __construct(public BenchmarkChildTarget $child) {}
}

final readonly class BenchmarkCollectionSource
{
    /** @param list<BenchmarkChildSource> $children */
    public function __construct(public array $children) {}
}

final readonly class BenchmarkCollectionTarget
{
    /** @param list<BenchmarkChildTarget> $children */
    public function __construct(public array $children) {}
}

$cacheDirectory = \sys_get_temp_dir() . '/object-mapper-benchmark-' . \bin2hex(\random_bytes(8));

try {
    $definitions = [
        new MappingDefinition(BenchmarkSimpleSource::class, BenchmarkSimpleTarget::class),
        new MappingDefinition(BenchmarkChildSource::class, BenchmarkChildTarget::class),
        new MappingDefinition(BenchmarkNestedSource::class, BenchmarkNestedTarget::class, [
            'child' => MapRule::from('child')->nested(BenchmarkChildTarget::class),
        ]),
        new MappingDefinition(BenchmarkCollectionSource::class, BenchmarkCollectionTarget::class, [
            'children' => MapRule::from('children')->collection(BenchmarkChildSource::class, BenchmarkChildTarget::class),
        ]),
    ];
    $defaultWarmupMemoryBefore = \memory_get_usage();
    $defaultMapperAndCache     = \createMapperAndCache($cacheDirectory, $definitions);
    $defaultMapper          = $defaultMapperAndCache['mapper'];
    $defaultMapper->warmup();
    $defaultWarmupMemoryAfter = \memory_get_usage();

    $preparedWarmupMemoryBefore = \memory_get_usage();
    $preparedMapperAndCache     = \createMapperAndCache($cacheDirectory, $definitions, true);
    $preparedMapper             = $preparedMapperAndCache['mapper'];
    $preparedMapper->warmup();
    $preparedWarmupMemoryAfter = \memory_get_usage();

    $warmupMemory = [
        'default_warmup_retained_memory_delta_bytes'       => $defaultWarmupMemoryAfter - $defaultWarmupMemoryBefore,
        'prepared_warmup_incremental_memory_delta_bytes' => $preparedWarmupMemoryAfter - $preparedWarmupMemoryBefore,
    ];
    $generatedSimpleMapper = $defaultMapperAndCache['cache']->get($definitions[0]);

    $simpleSource = new BenchmarkSimpleSource(42, 'Ada Lovelace', true);
    $nestedSource = new BenchmarkNestedSource(new BenchmarkChildSource('child'));
    $children     = [];
    for ($index = 0; $index < COLLECTION_SIZE; ++$index) {
        $children[] = new BenchmarkChildSource('child-' . $index);
    }

    $collectionSource = new BenchmarkCollectionSource($children);
    $metrics          = [
        'direct_constructor'       => \benchmark(
            static fn (): BenchmarkSimpleTarget => new BenchmarkSimpleTarget($simpleSource->id, $simpleSource->name, $simpleSource->active),
            SIMPLE_ITERATIONS,
        ),
        'generated_simple_mapping' => \benchmark(
            static fn (): object => $generatedSimpleMapper->map($simpleSource),
            SIMPLE_ITERATIONS,
        ),
        'default_mapping'          => [
            'simple'     => \benchmark(
                static fn (): object => $defaultMapper->map($simpleSource, BenchmarkSimpleTarget::class),
                SIMPLE_ITERATIONS,
            ),
            'nested'     => \benchmark(
                static fn (): object => $defaultMapper->map($nestedSource, BenchmarkNestedTarget::class),
                NESTED_ITERATIONS,
            ),
            'collection' => \benchmark(
                static fn (): object => $defaultMapper->map($collectionSource, BenchmarkCollectionTarget::class),
                COLLECTION_ITERATIONS,
            ),
        ],
        'prepared_mapping'         => [
            'simple'     => \benchmark(
                static fn (): object => $preparedMapper->map($simpleSource, BenchmarkSimpleTarget::class),
                SIMPLE_ITERATIONS,
            ),
            'nested'     => \benchmark(
                static fn (): object => $preparedMapper->map($nestedSource, BenchmarkNestedTarget::class),
                NESTED_ITERATIONS,
            ),
            'collection' => \benchmark(
                static fn (): object => $preparedMapper->map($collectionSource, BenchmarkCollectionTarget::class),
                COLLECTION_ITERATIONS,
            ),
        ],
        'cold_first_mapping'       => \benchmarkColdFirstMapping($definitions),
        'warmup_memory'            => $warmupMemory,
    ];

    foreach (['default_mapping', 'prepared_mapping'] as $mode) {
        $metrics[$mode]['collection']['items_per_second'] = \round(
            $metrics[$mode]['collection']['operations_per_second'] * COLLECTION_SIZE,
            1,
        );
    }

    echo \json_encode([
    'environment' => [
        'php'             => PHP_VERSION,
        'sapi'            => PHP_SAPI,
        'opcache_cli'     => (bool) \ini_get('opcache.enable_cli'),
        'jit_buffer_size' => (int) \ini_get('opcache.jit_buffer_size'),
        'cpu_clock'       => \function_exists('getrusage') ? 'process_getrusage' : 'unavailable',
        'rounds'          => ROUNDS,
    ],
    'measurement_scope' => [
        'cpu'    => 'Current PHP process only; child-process CPU time is excluded.',
        'memory' => 'PHP allocator; per-round deltas are measured after warmup. Prepared warmup memory is incremental after default warmup.',
    ],
        'workload'    => [
            'simple_iterations'     => SIMPLE_ITERATIONS,
            'nested_iterations'     => NESTED_ITERATIONS,
            'collection_iterations' => COLLECTION_ITERATIONS,
            'collection_size'       => COLLECTION_SIZE,
        ],
        'metrics'     => $metrics,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} finally {
    \deleteDirectory($cacheDirectory);
}

/**
 * @param list<MappingDefinition> $definitions
 */
function createMapper(string $cacheDirectory, array $definitions): ObjectMapper
{
    return \createMapperAndCache($cacheDirectory, $definitions)['mapper'];
}

/**
 * @param list<MappingDefinition> $definitions
 *
 * @return array{mapper: ObjectMapper, cache: MapperCache}
 */
function createMapperAndCache(string $cacheDirectory, array $definitions, bool $reusePreparedMappings = false): array
{
    $transformers = new ValueTransformerRegistry();
    $registry     = new MappingRegistry($definitions);
    $cache        = new MapperCache(
        new MappingMetadataFactory($transformers, mappingRegistry: $registry),
        new PhpMapperGenerator(),
        $cacheDirectory,
        $transformers,
        true,
        $registry,
        reusePreparedMappings: $reusePreparedMappings,
    );

    return [
        'mapper' => new ObjectMapper($registry, $cache),
        'cache'  => $cache,
    ];
}

/**
 * @param Closure(): object $operation
 *
 * @return array{
 *     median_ms: float,
 *     operations_per_second: float,
 *     median_user_cpu_ms: float|null,
 *     median_system_cpu_ms: float|null,
 *     median_cpu_ms: float|null,
 *     cpu_utilization_percent: float|null,
 *     cpu_ms_per_operation: float|null,
 *     peak_memory_bytes: int,
 *     peak_memory_used_bytes: int,
 *     median_peak_memory_delta_bytes: int,
 *     median_retained_memory_delta_bytes: int,
 *     median_peak_memory_used_delta_bytes: int,
 *     median_retained_memory_used_delta_bytes: int
 * }
 */
function benchmark(Closure $operation, int $iterations): array
{
    $durations           = [];
    $userCpuDurations    = [];
    $systemCpuDurations  = [];
    $peakMemory          = 0;
    $peakMemoryUsed      = 0;
    $peakMemoryDeltas    = [];
    $retainedMemoryDeltas = [];
    $peakMemoryUsedDeltas = [];
    $retainedMemoryUsedDeltas = [];
    $operation();

    for ($round = 0; $round < ROUNDS; ++$round) {
        \memory_reset_peak_usage();
        $memoryBefore     = \memory_get_usage(true);
        $memoryUsedBefore = \memory_get_usage();
        $usageBefore      = \resourceUsage();
        $startedAt        = \hrtime(true);
        for ($index = 0; $index < $iterations; ++$index) {
            $operation();
        }

        $usageAfter              = \resourceUsage();
        $durations[]             = (\hrtime(true) - $startedAt) / 1_000_000;
        $peakMemory                  = \max($peakMemory, \memory_get_peak_usage(true));
        $peakMemoryUsed              = \max($peakMemoryUsed, \memory_get_peak_usage());
        $peakMemoryDeltas[]          = \memory_get_peak_usage(true) - $memoryBefore;
        $retainedMemoryDeltas[]      = \memory_get_usage(true) - $memoryBefore;
        $peakMemoryUsedDeltas[]      = \memory_get_peak_usage() - $memoryUsedBefore;
        $retainedMemoryUsedDeltas[]  = \memory_get_usage() - $memoryUsedBefore;
        $userCpuDuration          = \resourceUsageDuration($usageBefore, $usageAfter, 'user_ms');
        $systemCpuDuration        = \resourceUsageDuration($usageBefore, $usageAfter, 'system_ms');
        if (null !== $userCpuDuration && null !== $systemCpuDuration) {
            $userCpuDurations[]   = $userCpuDuration;
            $systemCpuDurations[] = $systemCpuDuration;
        }
    }

    $median          = \median($durations);
    $medianUserCpu   = \medianOrNull($userCpuDurations);
    $medianSystemCpu = \medianOrNull($systemCpuDurations);
    $medianCpu       = null === $medianUserCpu || null === $medianSystemCpu ? null : $medianUserCpu + $medianSystemCpu;

    return [
        'median_ms'             => \round($median, 3),
        'operations_per_second' => \round($iterations / ($median / 1_000), 1),
        'median_user_cpu_ms'    => null === $medianUserCpu ? null : \round($medianUserCpu, 3),
        'median_system_cpu_ms'  => null === $medianSystemCpu ? null : \round($medianSystemCpu, 3),
        'median_cpu_ms'         => null === $medianCpu ? null : \round($medianCpu, 3),
        'cpu_utilization_percent' => null === $medianCpu ? null : \round($medianCpu / $median * 100, 1),
        'cpu_ms_per_operation'    => null === $medianCpu ? null : \round($medianCpu / $iterations, 6),
        'peak_memory_bytes'     => $peakMemory,
        'peak_memory_used_bytes' => $peakMemoryUsed,
        'median_peak_memory_delta_bytes'     => (int) \median($peakMemoryDeltas),
        'median_retained_memory_delta_bytes' => (int) \median($retainedMemoryDeltas),
        'median_peak_memory_used_delta_bytes'     => (int) \median($peakMemoryUsedDeltas),
        'median_retained_memory_used_delta_bytes' => (int) \median($retainedMemoryUsedDeltas),
    ];
}

/**
 * @param list<MappingDefinition> $definitions
 *
 * @return array{
 *     median_ms: float,
 *     median_user_cpu_ms: float|null,
 *     median_system_cpu_ms: float|null,
 *     median_cpu_ms: float|null,
 *     peak_memory_bytes: int,
 *     peak_memory_used_bytes: int,
 *     median_peak_memory_delta_bytes: int,
 *     median_retained_memory_delta_bytes: int,
 *     median_peak_memory_used_delta_bytes: int,
 *     median_retained_memory_used_delta_bytes: int
 * }
 */
function benchmarkColdFirstMapping(array $definitions): array
{
    $durations           = [];
    $userCpuDurations    = [];
    $systemCpuDurations  = [];
    $peakMemory          = 0;
    $peakMemoryUsed      = 0;
    $peakMemoryDeltas    = [];
    $retainedMemoryDeltas = [];
    $peakMemoryUsedDeltas = [];
    $retainedMemoryUsedDeltas = [];

    for ($round = 0; $round < ROUNDS; ++$round) {
        $cacheDirectory = \sys_get_temp_dir() . '/object-mapper-benchmark-cold-' . \bin2hex(\random_bytes(8));
        \memory_reset_peak_usage();
        $memoryBefore     = \memory_get_usage(true);
        $memoryUsedBefore = \memory_get_usage();
        $usageBefore      = \resourceUsage();
        $startedAt        = \hrtime(true);

        try {
            \createMapper($cacheDirectory, $definitions)->map(
                new BenchmarkSimpleSource(42, 'Ada Lovelace', true),
                BenchmarkSimpleTarget::class,
            );
            $usageAfter              = \resourceUsage();
            $durations[]             = (\hrtime(true) - $startedAt) / 1_000_000;
            $peakMemory                  = \max($peakMemory, \memory_get_peak_usage(true));
            $peakMemoryUsed              = \max($peakMemoryUsed, \memory_get_peak_usage());
            $peakMemoryDeltas[]          = \memory_get_peak_usage(true) - $memoryBefore;
            $retainedMemoryDeltas[]      = \memory_get_usage(true) - $memoryBefore;
            $peakMemoryUsedDeltas[]      = \memory_get_peak_usage() - $memoryUsedBefore;
            $retainedMemoryUsedDeltas[]  = \memory_get_usage() - $memoryUsedBefore;
            $userCpuDuration          = \resourceUsageDuration($usageBefore, $usageAfter, 'user_ms');
            $systemCpuDuration        = \resourceUsageDuration($usageBefore, $usageAfter, 'system_ms');
            if (null !== $userCpuDuration && null !== $systemCpuDuration) {
                $userCpuDurations[]   = $userCpuDuration;
                $systemCpuDurations[] = $systemCpuDuration;
            }
        } finally {
            \deleteDirectory($cacheDirectory);
        }
    }

    $median          = \median($durations);
    $medianUserCpu   = \medianOrNull($userCpuDurations);
    $medianSystemCpu = \medianOrNull($systemCpuDurations);
    $medianCpu       = null === $medianUserCpu || null === $medianSystemCpu ? null : $medianUserCpu + $medianSystemCpu;

    return [
        'median_ms'                         => \round($median, 3),
        'median_user_cpu_ms'                => null === $medianUserCpu ? null : \round($medianUserCpu, 3),
        'median_system_cpu_ms'              => null === $medianSystemCpu ? null : \round($medianSystemCpu, 3),
        'median_cpu_ms'                     => null === $medianCpu ? null : \round($medianCpu, 3),
        'peak_memory_bytes'                 => $peakMemory,
        'peak_memory_used_bytes'            => $peakMemoryUsed,
        'median_peak_memory_delta_bytes'    => (int) \median($peakMemoryDeltas),
        'median_retained_memory_delta_bytes' => (int) \median($retainedMemoryDeltas),
        'median_peak_memory_used_delta_bytes' => (int) \median($peakMemoryUsedDeltas),
        'median_retained_memory_used_delta_bytes' => (int) \median($retainedMemoryUsedDeltas),
    ];
}

/**
 * @return array{user_ms: float, system_ms: float}|null
 */
function resourceUsage(): ?array
{
    if (! \function_exists('getrusage')) {
        return null;
    }

    $usage = \getrusage();

    return [
        'user_ms'   => ((float) ($usage['ru_utime.tv_sec'] ?? 0) * 1_000 + (float) ($usage['ru_utime.tv_usec'] ?? 0) / 1_000),
        'system_ms' => ((float) ($usage['ru_stime.tv_sec'] ?? 0) * 1_000 + (float) ($usage['ru_stime.tv_usec'] ?? 0) / 1_000),
    ];
}

/**
 * @param array{user_ms: float, system_ms: float}|null $before
 * @param array{user_ms: float, system_ms: float}|null $after
 */
function resourceUsageDuration(?array $before, ?array $after, string $metric): ?float
{
    if (null === $before || null === $after) {
        return null;
    }

    return $after[$metric] - $before[$metric];
}

/**
 * @param list<float|int> $values
 */
function median(array $values): float
{
    \sort($values);

    return (float) $values[\intdiv(\count($values), 2)];
}

/**
 * @param list<float> $values
 */
function medianOrNull(array $values): ?float
{
    return [] === $values ? null : \median($values);
}

function deleteDirectory(string $directory): void
{
    if (! \is_dir($directory)) {
        return;
    }

    foreach (\scandir($directory) ?: [] as $entry) {
        if ('.' === $entry || '..' === $entry) {
            continue;
        }

        \unlink($directory . DIRECTORY_SEPARATOR . $entry);
    }

    \rmdir($directory);
}
