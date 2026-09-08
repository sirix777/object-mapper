<?php

declare(strict_types=1);

use Sirix\ObjectMapper\Contract\CustomObjectMapperInterface;
use Sirix\ObjectMapper\Contract\CustomObjectMapperProviderInterface;
use Sirix\ObjectMapper\Contract\MappingDefinitionInterface;
use Sirix\ObjectMapper\Contract\ValueTransformerInterface;
use Sirix\ObjectMapper\Definition\CustomMappingDefinition;
use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Definition\MapRule;
use Sirix\ObjectMapper\Definition\ProviderCustomMappingDefinition;
use Sirix\ObjectMapper\Exception\MappingExecutionFailed;
use Sirix\ObjectMapper\Generator\MapperCache;
use Sirix\ObjectMapper\Generator\PhpMapperGenerator;
use Sirix\ObjectMapper\Metadata\MappingMetadataFactory;
use Sirix\ObjectMapper\Runtime\MappingRegistry;
use Sirix\ObjectMapper\Runtime\ObjectMapper;
use Sirix\ObjectMapper\Runtime\ValueTransformerRegistry;

require \dirname(__DIR__) . '/vendor/autoload.php';

$options     = \getopt('', ['workload:', 'iterations:', 'simple-iterations:', 'getter-iterations:', 'nested-iterations:', 'collection-iterations:', 'context-iterations:', 'fiber-iterations:', 'rounds:', 'runtime-root:', 'execution-context-mode:']);
$runtimeRoot = \benchmarkRuntimeRoot($options);
\registerBenchmarkRuntimeAutoloader($runtimeRoot);
$executionContextModes  = \benchmarkExecutionContextModes($options);
$runtimeIsolation       = \verifyBenchmarkRuntimeIsolation($runtimeRoot, [
    CustomObjectMapperInterface::class,
    CustomObjectMapperProviderInterface::class,
    MappingDefinitionInterface::class,
    ValueTransformerInterface::class,
    CustomMappingDefinition::class,
    MappingDefinition::class,
    MapRule::class,
    ProviderCustomMappingDefinition::class,
    MappingExecutionFailed::class,
    MapperCache::class,
    PhpMapperGenerator::class,
    MappingMetadataFactory::class,
    MappingRegistry::class,
    ObjectMapper::class,
    ValueTransformerRegistry::class,
]);
$workload              = $options['workload'] ?? 'all';
if (! \in_array($workload, ['all', 'collection', 'collections', 'legacy', 'leaves', 'flat', 'getter-transformer', 'nested', 'contexts', 'deep', 'diamond', 'fiber', 'failure'], true)) {
    throw new InvalidArgumentException('Workload must be all, collections, legacy, leaves, flat, getter-transformer, nested, contexts, deep, diamond, fiber, or failure.');
}
$runLegacy = \in_array($workload, ['all', 'legacy'], true);
// Per-scenario options override the shared bound without inflating collection runs.
\define('SIMPLE_ITERATIONS', \benchmarkOption($options, 'simple-iterations', \benchmarkOption($options, 'iterations', 20_000, 1_000_000), 1_000_000));
\define('GETTER_ITERATIONS', \benchmarkOption($options, 'getter-iterations', SIMPLE_ITERATIONS, 1_000_000));
\define('NESTED_ITERATIONS', \benchmarkOption($options, 'nested-iterations', \benchmarkOption($options, 'iterations', 10_000, 1_000_000), 1_000_000));
\define('COLLECTION_ITERATIONS', \benchmarkOption($options, 'collection-iterations', \benchmarkOption($options, 'iterations', 100, 1_000_000), 1_000_000));
\define('CONTEXT_ITERATIONS', \benchmarkOption($options, 'context-iterations', \benchmarkOption($options, 'iterations', 10_000, 1_000_000), 1_000_000));
\define('FIBER_ITERATIONS', \benchmarkOption($options, 'fiber-iterations', \benchmarkOption($options, 'iterations', 1_000, 1_000_000), 1_000_000));
const COLLECTION_SIZE       = 100;
\define('ROUNDS', \benchmarkOption($options, 'rounds', 5, 100));

final readonly class BenchmarkSimpleSource
{
    public function __construct(public int $id, public string $name, public bool $active) {}
}

final readonly class BenchmarkSimpleTarget
{
    public function __construct(public int $id, public string $name, public bool $active) {}
}

final readonly class BenchmarkGetterSource
{
    public function __construct(private string $value) {}

    public function getValue(): string
    {
        return $this->value;
    }
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

final readonly class BenchmarkDeepSource
{
    public function __construct(public BenchmarkDeepMiddleSource $middle) {}
}

final readonly class BenchmarkDeepTarget
{
    public function __construct(public BenchmarkDeepMiddleTarget $middle) {}
}

final readonly class BenchmarkDeepMiddleSource
{
    public function __construct(public BenchmarkNestedSource $nested) {}
}

final readonly class BenchmarkDeepMiddleTarget
{
    public function __construct(public BenchmarkNestedTarget $nested) {}
}

final readonly class BenchmarkDiamondSource
{
    public function __construct(public BenchmarkChildSource $left, public BenchmarkChildSource $right) {}
}

final readonly class BenchmarkDiamondTarget
{
    public function __construct(public BenchmarkChildTarget $left, public BenchmarkChildTarget $right) {}
}

final readonly class BenchmarkFailureSource
{
    public function __construct(public string $value) {}
}

final readonly class BenchmarkFailureTarget
{
    public function __construct(public string $value) {}
}

final readonly class BenchmarkStructuralFailureSource
{
    public function __construct(public BenchmarkFailureSource $child) {}
}

final readonly class BenchmarkStructuralFailureTarget
{
    public function __construct(public BenchmarkFailureTarget $child) {}
}

final readonly class BenchmarkFiberSource
{
    public function __construct(public BenchmarkChildSource $child, public string $value) {}
}

final readonly class BenchmarkFiberTarget
{
    public function __construct(public BenchmarkChildTarget $child, public string $value) {}
}

final readonly class BenchmarkCollectionSource
{
    /** @param list<BenchmarkChildSource|BenchmarkNestedSource> $children */
    public function __construct(public array $children) {}
}

final readonly class BenchmarkCollectionTarget
{
    /** @param list<BenchmarkChildTarget|BenchmarkNestedTarget> $children */
    public function __construct(public array $children) {}
}

final class BenchmarkTransformer implements ValueTransformerInterface
{
    public function transform(string $value): string
    {
        return \strtoupper($value);
    }
}

final class BenchmarkFailingTransformer implements ValueTransformerInterface
{
    public function transform(string $value): string
    {
        throw new RuntimeException('Benchmark failure.');
    }
}

final class BenchmarkFiberSuspendingTransformer implements ValueTransformerInterface
{
    public function transform(string $value): string
    {
        Fiber::suspend($value);

        return $value;
    }
}

final class BenchmarkCustomMapper implements CustomObjectMapperInterface
{
    public function map(object $source): object
    {
        if (! $source instanceof BenchmarkChildSource) {
            throw new InvalidArgumentException('Unexpected benchmark source.');
        }

        return new BenchmarkChildTarget($source->value);
    }
}

final readonly class BenchmarkProvider implements CustomObjectMapperProviderInterface
{
    public function __construct(private BenchmarkCustomMapper $mapper) {}

    public function get(string $mapperId): CustomObjectMapperInterface
    {
        if ('benchmark-child' !== $mapperId) {
            throw new InvalidArgumentException('Unknown benchmark mapper.');
        }

        return $this->mapper;
    }
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
    $defaultMapper             = $defaultMapperAndCache['mapper'];
    $defaultMapper->warmup();
    $defaultWarmupMemoryAfter = \memory_get_usage();

    $preparedWarmupMemoryBefore = \memory_get_usage();
    $preparedMapperAndCache     = \createMapperAndCache($cacheDirectory, $definitions, true);
    $preparedMapper             = $preparedMapperAndCache['mapper'];
    $preparedMapper->warmup();
    $preparedWarmupMemoryAfter = \memory_get_usage();

    $warmupMemory = [
        'default_warmup_retained_memory_delta_bytes'       => $defaultWarmupMemoryAfter - $defaultWarmupMemoryBefore,
        'prepared_warmup_incremental_memory_delta_bytes'   => $preparedWarmupMemoryAfter - $preparedWarmupMemoryBefore,
    ];
    $generatedSimpleMapper = $defaultMapperAndCache['cache']->get($definitions[0]);

    $simpleSource = new BenchmarkSimpleSource(42, 'Ada Lovelace', true);
    $nestedSource = new BenchmarkNestedSource(new BenchmarkChildSource('child'));
    $children     = [];
    for ($index = 0; $index < COLLECTION_SIZE; ++$index) {
        $children[] = new BenchmarkChildSource('child-' . $index);
    }

    $collectionSource = new BenchmarkCollectionSource($children);
    $metrics          = ! $runLegacy ? [] : [
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

    foreach ($runLegacy ? ['default_mapping', 'prepared_mapping'] : [] as $mode) {
        $metrics[$mode]['collection']['items_per_second'] = \round(
            $metrics[$mode]['collection']['operations_per_second'] * COLLECTION_SIZE,
            1,
        );
    }

    if (\in_array($workload, ['all', 'collections'], true)) {
        $metrics['collection_matrix'] = \benchmarkCollections($cacheDirectory);
    }

    if (\in_array($workload, ['all', 'leaves', 'flat', 'getter-transformer', 'nested'], true)) {
        $metrics['leaf_workloads'] = \benchmarkLeaves($cacheDirectory, $workload);
    }

    if (\in_array($workload, ['all', 'contexts', 'flat', 'collection', 'deep', 'diamond', 'fiber', 'failure'], true)) {
        $metrics['execution_context_workloads'] = \benchmarkExecutionContexts($cacheDirectory, $workload, $executionContextModes);
    }

    echo \json_encode([
        'environment'       => [
            'php'                 => PHP_VERSION,
            'sapi'                => PHP_SAPI,
            'opcache_cli'         => (bool) \ini_get('opcache.enable_cli'),
            'jit_buffer_size'     => (int) \ini_get('opcache.jit_buffer_size'),
            'jit'                 => \ini_get('opcache.jit'),
            'opcache_status'      => \function_exists('opcache_get_status') ? \opcache_get_status(false) : false,
            'extensions'          => \get_loaded_extensions(),
            'pcov_enabled'        => (bool) \ini_get('pcov.enabled'),
            'pcov_directory'      => \ini_get('pcov.directory'),
            'xdebug_modes'        => \function_exists('xdebug_info') ? \xdebug_info('mode') : [],
            'cpu_clock'           => \function_exists('getrusage') ? 'process_getrusage' : 'unavailable',
            'rounds'              => ROUNDS,
            'runtime_root'        => $runtimeRoot,
            'runtime_source_hash' => \benchmarkRuntimeSourceHash($runtimeRoot),
            'harness_sha256'      => \hash_file('sha256', __FILE__),
            'runtime_isolation'   => $runtimeIsolation,
        ],
        'measurement_scope' => [
            'cpu'    => 'Current PHP process only; child-process CPU time is excluded.',
            'memory' => 'PHP allocator; per-round deltas are measured after warmup. Prepared warmup memory is incremental after default warmup.',
        ],
        'workload'          => [
            'selection'               => $workload,
            'execution_context_modes' => $executionContextModes,
            'simple_iterations'       => SIMPLE_ITERATIONS,
            'getter_iterations'       => GETTER_ITERATIONS,
            'nested_iterations'       => NESTED_ITERATIONS,
            'collection_iterations'   => COLLECTION_ITERATIONS,
            'context_iterations'      => CONTEXT_ITERATIONS,
            'fiber_iterations'        => FIBER_ITERATIONS,
            'collection_size'         => COLLECTION_SIZE,
            'collection_sizes'        => [0, 1, 100, 1000],
        ],
        'metrics'           => $metrics,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} finally {
    \deleteDirectory($cacheDirectory);
}

/**
 * @param list<MappingDefinitionInterface> $definitions
 */
function createMapper(string $cacheDirectory, array $definitions): ObjectMapper
{
    return \createMapperAndCache($cacheDirectory, $definitions)['mapper'];
}

/**
 * @param list<MappingDefinitionInterface> $definitions
 *
 * @return array{mapper: ObjectMapper, cache: MapperCache}
 */
function createMapperAndCache(string $cacheDirectory, array $definitions, bool $reusePreparedMappings = false, ?CustomObjectMapperProviderInterface $provider = null, ?ValueTransformerRegistry $transformers = null): array
{
    $transformers ??= new ValueTransformerRegistry();
    $registry     = new MappingRegistry($definitions);
    $cache        = new MapperCache(
        new MappingMetadataFactory($transformers, mappingRegistry: $registry),
        new PhpMapperGenerator(),
        $cacheDirectory,
        $transformers,
        true,
        $registry,
        customObjectMapperProvider: $provider,
        reusePreparedMappings: $reusePreparedMappings,
    );

    return [
        'mapper' => new ObjectMapper($registry, $cache, $provider),
        'cache'  => $cache,
    ];
}

/** @param array<string, mixed> $options */
function benchmarkOption(array $options, string $name, int $default, int $maximum): int
{
    if (! \array_key_exists($name, $options)) {
        return $default;
    }

    $value = \filter_var($options[$name], FILTER_VALIDATE_INT, [
        'options' => [
            'min_range' => 1,
            'max_range' => $maximum,
        ],
    ]);
    if (false === $value) {
        throw new InvalidArgumentException(\sprintf('--%s must be an integer between 1 and %d.', $name, $maximum));
    }

    return $value;
}

/** @param array<string, mixed> $options */
function benchmarkRuntimeRoot(array $options): string
{
    $runtimeRoot = $options['runtime-root'] ?? \dirname(__DIR__);
    if (! \is_string($runtimeRoot) || '' === $runtimeRoot) {
        throw new InvalidArgumentException('--runtime-root must name a runtime root directory.');
    }

    $runtimeRoot = \realpath($runtimeRoot);
    if (false === $runtimeRoot || ! \is_dir($runtimeRoot . '/src')) {
        throw new InvalidArgumentException('--runtime-root must contain a src directory.');
    }

    return $runtimeRoot;
}

/**
 * @param array<string, mixed> $options
 *
 * @return list<string>
 */
function benchmarkExecutionContextModes(array $options): array
{
    $mode = $options['execution-context-mode'] ?? 'both';
    if (! \is_string($mode) || ! \in_array($mode, ['both', 'default', 'prepared'], true)) {
        throw new InvalidArgumentException('--execution-context-mode must be both, default, or prepared.');
    }

    return 'both' === $mode ? ['default', 'prepared'] : [$mode];
}

function registerBenchmarkRuntimeAutoloader(string $runtimeRoot): void
{
    \spl_autoload_register(static function(string $class) use ($runtimeRoot): void {
        $prefix = 'Sirix\ObjectMapper\\';
        if (! \str_starts_with($class, $prefix)) {
            return;
        }

        $path = $runtimeRoot . '/src/' . \str_replace('\\', '/', \substr($class, \strlen($prefix))) . '.php';
        if (! \is_file($path)) {
            throw new RuntimeException(\sprintf('Selected runtime snapshot does not contain %s for %s.', $path, $class));
        }

        require $path;
        if (! \class_exists($class, false) && ! \interface_exists($class, false) && ! \trait_exists($class, false) && ! \enum_exists($class, false)) {
            throw new RuntimeException(\sprintf('Selected runtime file %s does not define %s.', $path, $class));
        }
    }, true, true);
}

/**
 * @param list<class-string> $classes
 *
 * @return array{status: string, class_files: array<class-string, string>}
 */
function verifyBenchmarkRuntimeIsolation(string $runtimeRoot, array $classes): array
{
    $sourceDirectory = \realpath($runtimeRoot . '/src');
    if (false === $sourceDirectory) {
        throw new RuntimeException('Selected runtime src directory cannot be resolved.');
    }

    $sourcePrefix = $sourceDirectory . DIRECTORY_SEPARATOR;
    $classFiles   = [];
    foreach ($classes as $class) {
        $file = (new ReflectionClass($class))->getFileName();
        $file = false === $file ? false : \realpath($file);
        if (false === $file || ! \str_starts_with($file, $sourcePrefix)) {
            throw new RuntimeException(\sprintf('Runtime class %s was not loaded from selected runtime src directory %s.', $class, $sourceDirectory));
        }

        $classFiles[$class] = $file;
    }

    return [
        'status'      => 'verified',
        'class_files' => $classFiles,
    ];
}

function benchmarkRuntimeSourceHash(string $runtimeRoot): string
{
    $sourceDirectory = $runtimeRoot . '/src';
    $files           = [];
    $iterator        = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDirectory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $files[] = $file->getPathname();
        }
    }
    \sort($files, SORT_STRING);

    $context = \hash_init('sha256');
    foreach ($files as $file) {
        \hash_update($context, \substr($file, \strlen($sourceDirectory) + 1));
        \hash_update_file($context, $file);
    }

    return \hash_final($context);
}

/** @return array<string, mixed> */
function benchmarkLeaves(string $cacheDirectory, string $selection): array
{
    $results = [];
    $shapes  = \in_array($selection, ['all', 'leaves'], true) ? ['flat', 'getter-transformer', 'nested'] : [$selection];
    foreach ($shapes as $shape) {
        $source = match ($shape) {
            'flat'               => new BenchmarkSimpleSource(42, 'Ada Lovelace', true),
            'getter-transformer' => new BenchmarkGetterSource('leaf'),
            'nested'             => new BenchmarkNestedSource(new BenchmarkChildSource('leaf')),
        };
        $expected = match ($shape) {
            'flat'               => new BenchmarkSimpleTarget(42, 'Ada Lovelace', true),
            'getter-transformer' => new BenchmarkChildTarget('LEAF'),
            'nested'             => new BenchmarkNestedTarget(new BenchmarkChildTarget('leaf')),
        };
        $definition = new MappingDefinition($source::class, $expected::class, match ($shape) {
            'flat'               => [],
            'getter-transformer' => [
                'value' => MapRule::fromGetter('getValue')->through(BenchmarkTransformer::class),
            ],
            'nested'             => [
                'child' => MapRule::from('child')->nested(BenchmarkChildTarget::class),
            ],
        });
        $definitions = [$definition];
        if ('nested' === $shape) {
            $definitions[] = new MappingDefinition(BenchmarkChildSource::class, BenchmarkChildTarget::class);
        }
        $iterations = match ($shape) {
            'flat'               => SIMPLE_ITERATIONS,
            'getter-transformer' => GETTER_ITERATIONS,
            'nested'             => NESTED_ITERATIONS,
        };
        $results[$shape]['iterations'] = $iterations;
        foreach ([false, true] as $prepared) {
            $mode = $prepared ? 'prepared' : 'default';
            \gc_collect_cycles();
            $memoryBefore    = \memory_get_usage();
            $allocatorBefore = \memory_get_usage(true);
            $mapperAndCache  = \createMapperAndCache(
                $cacheDirectory,
                $definitions,
                $prepared,
                transformers: new ValueTransformerRegistry([new BenchmarkTransformer()]),
            );
            $mapper = $mapperAndCache['mapper'];
            $mapper->warmup();
            $retained               = \memory_get_usage() - $memoryBefore;
            $allocatorRetained      = \memory_get_usage(true) - $allocatorBefore;
            $operation              = static fn (): object => $mapper->map($source, $expected::class);
            $results[$shape][$mode] = [
                'warmup_retained_memory_delta_bytes'           => $retained,
                'warmup_allocator_retained_memory_delta_bytes' => $allocatorRetained,
                'steady_state'                                 => \benchmarkVerified($operation, $expected, $iterations),
            ];
            if ('flat' === $shape && ! $prepared) {
                $generatedMapper                             = $mapperAndCache['cache']->get($definition);
                $results[$shape]['generated_simple_mapping'] = \benchmarkVerified(
                    static fn (): object => $generatedMapper->map($source),
                    $expected,
                    $iterations,
                );
                $results[$shape]['direct_constructor'] = \benchmarkVerified(
                    static fn (): BenchmarkSimpleTarget => new BenchmarkSimpleTarget($source->id, $source->name, $source->active),
                    $expected,
                    $iterations,
                );
                unset($generatedMapper);
            }
            unset($operation, $mapper, $mapperAndCache);
        }
    }

    return $results;
}

/**
 * @param list<string> $modes
 *
 * @return array<string, mixed>
 */
function benchmarkExecutionContexts(string $cacheDirectory, string $selection, array $modes): array
{
    $results = [];
    $shapes  = \in_array($selection, ['all', 'contexts'], true) ? ['flat', 'collection', 'deep', 'diamond', 'fiber', 'failure'] : [$selection];

    foreach ($shapes as $shape) {
        $childSource  = new BenchmarkChildSource('context-child');
        $childTarget  = new BenchmarkChildTarget('context-child');
        $nestedSource = new BenchmarkNestedSource($childSource);
        $nestedTarget = new BenchmarkNestedTarget($childTarget);
        $definitions  = [];
        $source       = null;
        $target       = null;
        $iterations   = CONTEXT_ITERATIONS;

        switch ($shape) {
            case 'flat':
                $source      = new BenchmarkSimpleSource(42, 'Ada Lovelace', true);
                $target      = new BenchmarkSimpleTarget(42, 'Ada Lovelace', true);
                $iterations  = SIMPLE_ITERATIONS;
                $definitions = [new MappingDefinition(BenchmarkSimpleSource::class, BenchmarkSimpleTarget::class)];

                break;

            case 'collection':
                $children = [];
                $targets  = [];
                for ($index = 0; $index < COLLECTION_SIZE; ++$index) {
                    $value      = 'context-child-' . $index;
                    $children[] = new BenchmarkChildSource($value);
                    $targets[]  = new BenchmarkChildTarget($value);
                }
                $source      = new BenchmarkCollectionSource($children);
                $target      = new BenchmarkCollectionTarget($targets);
                $iterations  = COLLECTION_ITERATIONS;
                $definitions = [
                    new MappingDefinition(BenchmarkCollectionSource::class, BenchmarkCollectionTarget::class, [
                        'children' => MapRule::from('children')->collection(BenchmarkChildSource::class, BenchmarkChildTarget::class),
                    ]),
                    new MappingDefinition(BenchmarkChildSource::class, BenchmarkChildTarget::class),
                ];

                break;

            case 'deep':
                $source      = new BenchmarkDeepSource(new BenchmarkDeepMiddleSource($nestedSource));
                $target      = new BenchmarkDeepTarget(new BenchmarkDeepMiddleTarget($nestedTarget));
                $definitions = [
                    new MappingDefinition(BenchmarkDeepSource::class, BenchmarkDeepTarget::class, [
                        'middle' => MapRule::from('middle')->nested(BenchmarkDeepMiddleTarget::class),
                    ]),
                    new MappingDefinition(BenchmarkDeepMiddleSource::class, BenchmarkDeepMiddleTarget::class, [
                        'nested' => MapRule::from('nested')->nested(BenchmarkNestedTarget::class),
                    ]),
                    new MappingDefinition(BenchmarkNestedSource::class, BenchmarkNestedTarget::class, [
                        'child' => MapRule::from('child')->nested(BenchmarkChildTarget::class),
                    ]),
                    new MappingDefinition(BenchmarkChildSource::class, BenchmarkChildTarget::class),
                ];

                break;

            case 'diamond':
                $source      = new BenchmarkDiamondSource($childSource, $childSource);
                $target      = new BenchmarkDiamondTarget($childTarget, $childTarget);
                $definitions = [
                    new MappingDefinition(BenchmarkDiamondSource::class, BenchmarkDiamondTarget::class, [
                        'left'  => MapRule::from('left')->nested(BenchmarkChildTarget::class),
                        'right' => MapRule::from('right')->nested(BenchmarkChildTarget::class),
                    ]),
                    new MappingDefinition(BenchmarkChildSource::class, BenchmarkChildTarget::class),
                ];

                break;

            case 'fiber':
                $source      = new BenchmarkFiberSource($childSource, 'fiber');
                $target      = new BenchmarkFiberTarget($childTarget, 'fiber');
                $iterations  = FIBER_ITERATIONS;
                $definitions = [
                    new MappingDefinition(BenchmarkFiberSource::class, BenchmarkFiberTarget::class, [
                        'child' => MapRule::from('child')->nested(BenchmarkChildTarget::class),
                        'value' => MapRule::from('value')->through(BenchmarkFiberSuspendingTransformer::class),
                    ]),
                    new MappingDefinition(BenchmarkChildSource::class, BenchmarkChildTarget::class),
                ];

                break;

            case 'failure':
                $source      = new BenchmarkFailureSource('failure');
                $target      = new BenchmarkFailureTarget('unreachable');
                $definitions = [
                    new MappingDefinition(BenchmarkFailureSource::class, BenchmarkFailureTarget::class, [
                        'value' => MapRule::from('value')->through(BenchmarkFailingTransformer::class),
                    ]),
                    new MappingDefinition(BenchmarkStructuralFailureSource::class, BenchmarkStructuralFailureTarget::class, [
                        'child' => MapRule::from('child')->nested(BenchmarkFailureTarget::class),
                    ]),
                ];

                break;
        }

        if (null === $source || null === $target || [] === $definitions) {
            throw new LogicException('Unknown execution-context benchmark workload.');
        }

        $results[$shape]['iterations'] = $iterations;
        foreach ($modes as $mode) {
            $prepared = 'prepared' === $mode;
            \gc_collect_cycles();
            $memoryBefore    = \memory_get_usage();
            $allocatorBefore = \memory_get_usage(true);
            $mapperAndCache  = \createMapperAndCache(
                $cacheDirectory,
                $definitions,
                $prepared,
                transformers: new ValueTransformerRegistry([new BenchmarkFailingTransformer(), new BenchmarkFiberSuspendingTransformer()]),
            );
            $mapper = $mapperAndCache['mapper'];
            $mapper->warmup();

            $results[$shape][$mode] = [
                'warmup_retained_memory_delta_bytes'           => \memory_get_usage() - $memoryBefore,
                'warmup_allocator_retained_memory_delta_bytes' => \memory_get_usage(true) - $allocatorBefore,
            ];
            $operation = static fn (): object => $mapper->map($source, $target::class);
            if ('failure' === $shape) {
                $results[$shape][$mode]['expected_failure']            = \benchmarkExpectedFailure($operation, $iterations);
                $structuralSource                                      = new BenchmarkStructuralFailureSource($source);
                $structuralOperation                                   = static fn (): object => $mapper->map($structuralSource, BenchmarkStructuralFailureTarget::class);
                $results[$shape][$mode]['structural_expected_failure'] = \benchmarkExpectedFailure($structuralOperation, $iterations);
                unset($structuralOperation);
            } elseif ('fiber' === $shape) {
                $results[$shape][$mode]['native_fiber'] = \benchmarkNativeFiber($operation, $target, $iterations);
            } else {
                $results[$shape][$mode]['steady_state'] = \benchmarkVerified($operation, $target, $iterations);
            }
            unset($operation, $mapper, $mapperAndCache);
        }
    }

    return $results;
}

/**
 * @param Closure(): object $operation
 *
 * @return array<string, mixed>
 */
function benchmarkVerified(Closure $operation, object $expected, int $iterations): array
{
    if ($operation() != $expected) {
        throw new RuntimeException('Leaf benchmark correctness check failed for ' . $expected::class . '.');
    }
    $measurement                                                         = \benchmark($operation, $iterations);
    $measurement['repeated_call_retained_memory_deltas_bytes']           = \benchmarkRetention($operation, $iterations);
    $measurement['repeated_call_allocator_retained_memory_deltas_bytes'] = \benchmarkAllocatorRetention($operation, $iterations);
    if ($operation() != $expected) {
        throw new RuntimeException('Leaf benchmark correctness check failed after repeated calls for ' . $expected::class . '.');
    }

    return $measurement;
}

/**
 * @param Closure(): object $operation
 *
 * @return array<string, mixed>
 */
function benchmarkExpectedFailure(Closure $operation, int $iterations): array
{
    \assertBenchmarkFailure($operation);
    $result      = new stdClass();
    $measurement = \benchmark(static function() use ($operation, $result): object {
        \assertBenchmarkFailure($operation);

        return $result;
    }, $iterations);
    $measurement['repeated_call_retained_memory_deltas_bytes']           = \benchmarkFailureRetention($operation, $iterations, false);
    $measurement['repeated_call_allocator_retained_memory_deltas_bytes'] = \benchmarkFailureRetention($operation, $iterations, true);
    \assertBenchmarkFailure($operation);

    return $measurement;
}

/**
 * @param Closure(): object $operation
 *
 * @return array<string, mixed>
 */
function benchmarkNativeFiber(Closure $operation, object $expected, int $iterations): array
{
    $durations                = [];
    $userCpuDurations         = [];
    $systemCpuDurations       = [];
    $peakMemory               = 0;
    $peakMemoryUsed           = 0;
    $peakMemoryDeltas         = [];
    $retainedMemoryDeltas     = [];
    $peakMemoryUsedDeltas     = [];
    $retainedMemoryUsedDeltas = [];

    \benchmarkFiberResult($operation, $iterations, $expected);
    for ($round = 0; $round < ROUNDS; ++$round) {
        // The transformer suspends while the root structural frame is active; setup remains outside timing.
        $mapped           = null;
        $fiber            = \createBenchmarkFiber($operation, $iterations, $mapped);
        $usageBefore      = \resourceUsage();
        $memoryBefore     = \memory_get_usage(true);
        $memoryUsedBefore = \memory_get_usage();
        \memory_reset_peak_usage();
        $startedAt = \hrtime(true);
        $fiber->start();
        for ($index = 0; $index < $iterations; ++$index) {
            $fiber->resume();
        }
        $elapsed             = (\hrtime(true) - $startedAt) / 1_000_000;
        $memoryAfter         = \memory_get_usage(true);
        $memoryUsedAfter     = \memory_get_usage();
        $roundPeakMemory     = \memory_get_peak_usage(true);
        $roundPeakMemoryUsed = \memory_get_peak_usage();
        $usageAfter          = \resourceUsage();
        if ($mapped != $expected) {
            throw new RuntimeException('Native Fiber benchmark correctness check failed.');
        }
        $durations[]                = $elapsed;
        $peakMemory                 = \max($peakMemory, $roundPeakMemory);
        $peakMemoryUsed             = \max($peakMemoryUsed, $roundPeakMemoryUsed);
        $peakMemoryDeltas[]         = $roundPeakMemory - $memoryBefore;
        $retainedMemoryDeltas[]     = $memoryAfter - $memoryBefore;
        $peakMemoryUsedDeltas[]     = $roundPeakMemoryUsed - $memoryUsedBefore;
        $retainedMemoryUsedDeltas[] = $memoryUsedAfter - $memoryUsedBefore;
        $userCpuDuration            = \resourceUsageDuration($usageBefore, $usageAfter, 'user_ms');
        $systemCpuDuration          = \resourceUsageDuration($usageBefore, $usageAfter, 'system_ms');
        if (null !== $userCpuDuration && null !== $systemCpuDuration) {
            $userCpuDurations[]   = $userCpuDuration;
            $systemCpuDurations[] = $systemCpuDuration;
        }
        unset($fiber);
    }

    $median          = \median($durations);
    $medianUserCpu   = \medianOrNull($userCpuDurations);
    $medianSystemCpu = \medianOrNull($systemCpuDurations);
    $medianCpu       = null === $medianUserCpu || null === $medianSystemCpu ? null : $medianUserCpu + $medianSystemCpu;

    return [
        'median_ms'                                            => \round($median, 3),
        'round_ms'                                             => $durations,
        'operations_per_second'                                => \round($iterations / ($median / 1_000), 1),
        'median_user_cpu_ms'                                   => null === $medianUserCpu ? null : \round($medianUserCpu, 3),
        'median_system_cpu_ms'                                 => null === $medianSystemCpu ? null : \round($medianSystemCpu, 3),
        'median_cpu_ms'                                        => null === $medianCpu ? null : \round($medianCpu, 3),
        'cpu_utilization_percent'                              => null === $medianCpu ? null : \round($medianCpu / $median * 100, 1),
        'cpu_ms_per_operation'                                 => null === $medianCpu ? null : \round($medianCpu / $iterations, 6),
        'peak_memory_bytes'                                    => $peakMemory,
        'peak_memory_used_bytes'                               => $peakMemoryUsed,
        'median_peak_memory_delta_bytes'                       => (int) \median($peakMemoryDeltas),
        'median_retained_memory_delta_bytes'                   => (int) \median($retainedMemoryDeltas),
        'median_peak_memory_used_delta_bytes'                  => (int) \median($peakMemoryUsedDeltas),
        'median_retained_memory_used_delta_bytes'              => (int) \median($retainedMemoryUsedDeltas),
        'repeated_call_retained_memory_deltas_bytes'           => \benchmarkFiberRetention($operation, $expected, $iterations, false),
        'repeated_call_allocator_retained_memory_deltas_bytes' => \benchmarkFiberRetention($operation, $expected, $iterations, true),
    ];
}

/** @param Closure(): object $operation */
function createBenchmarkFiber(Closure $operation, int $iterations, ?object &$mapped): Fiber
{
    return new Fiber(static function() use ($operation, $iterations, &$mapped): void {
        for ($index = 0; $index < $iterations; ++$index) {
            $mapped = $operation();
        }
    });
}

/** @param Closure(): object $operation */
function benchmarkFiberResult(Closure $operation, int $iterations, object $expected): void
{
    $mapped = null;
    $fiber  = \createBenchmarkFiber($operation, $iterations, $mapped);
    $fiber->start();
    for ($index = 0; $index < $iterations; ++$index) {
        $fiber->resume();
    }

    if ($mapped != $expected) {
        throw new RuntimeException('Native Fiber benchmark correctness check failed.');
    }
}

/**
 * @param Closure(): object $operation
 *
 * @return list<int>
 */
function benchmarkFiberRetention(Closure $operation, object $expected, int $iterations, bool $allocator): array
{
    $retention = [];
    for ($block = 0; $block < 2; ++$block) {
        \gc_collect_cycles();
        $before = \memory_get_usage($allocator);
        \benchmarkFiberResult($operation, $iterations, $expected);
        \gc_collect_cycles();
        $retention[] = \memory_get_usage($allocator) - $before;
    }

    return $retention;
}

/** @param Closure(): object $operation */
function assertBenchmarkFailure(Closure $operation): void
{
    try {
        $operation();
    } catch (MappingExecutionFailed) {
        return;
    }

    throw new RuntimeException('Failure benchmark operation unexpectedly succeeded.');
}

/**
 * @param Closure(): object $operation
 *
 * @return list<int>
 */
function benchmarkRetention(Closure $operation, int $iterations): array
{
    $retention = [];
    // Two successive blocks expose retention across repeated calls, outside timed rounds.
    for ($block = 0; $block < 2; ++$block) {
        \gc_collect_cycles();
        $before = \memory_get_usage();
        for ($index = 0; $index < $iterations; ++$index) {
            $operation();
        }
        \gc_collect_cycles();
        $retention[] = \memory_get_usage() - $before;
    }

    return $retention;
}

/**
 * @param Closure(): object $operation
 *
 * @return list<int>
 */
function benchmarkAllocatorRetention(Closure $operation, int $iterations): array
{
    $retention = [];
    for ($block = 0; $block < 2; ++$block) {
        \gc_collect_cycles();
        $before = \memory_get_usage(true);
        for ($index = 0; $index < $iterations; ++$index) {
            $operation();
        }
        \gc_collect_cycles();
        $retention[] = \memory_get_usage(true) - $before;
    }

    return $retention;
}

/**
 * @param Closure(): object $operation
 *
 * @return list<int>
 */
function benchmarkFailureRetention(Closure $operation, int $iterations, bool $allocator): array
{
    $retention = [];
    for ($block = 0; $block < 2; ++$block) {
        \gc_collect_cycles();
        $before = \memory_get_usage($allocator);
        for ($index = 0; $index < $iterations; ++$index) {
            \assertBenchmarkFailure($operation);
        }
        \gc_collect_cycles();
        $retention[] = \memory_get_usage($allocator) - $before;
    }

    return $retention;
}

/** @return array<string, mixed> */
function benchmarkCollections(string $cacheDirectory): array
{
    $results      = [];
    $customMapper = new BenchmarkCustomMapper();
    $provider     = new BenchmarkProvider($customMapper);
    $transformers = new ValueTransformerRegistry([new BenchmarkTransformer()]);
    foreach (['leaf', 'structural', 'transformer', 'custom', 'provider'] as $shape) {
        $elementSource   = 'structural' === $shape ? BenchmarkNestedSource::class : BenchmarkChildSource::class;
        $elementTarget   = 'structural' === $shape ? BenchmarkNestedTarget::class : BenchmarkChildTarget::class;
        $childDefinition = match ($shape) {
            'transformer' => new MappingDefinition(BenchmarkChildSource::class, BenchmarkChildTarget::class, [
                'value' => MapRule::from('value')->through(BenchmarkTransformer::class),
            ]),
            'custom'      => new CustomMappingDefinition(BenchmarkChildSource::class, BenchmarkChildTarget::class, $customMapper),
            'provider'    => new ProviderCustomMappingDefinition(BenchmarkChildSource::class, BenchmarkChildTarget::class, 'benchmark-child'),
            default       => new MappingDefinition(BenchmarkChildSource::class, BenchmarkChildTarget::class),
        };
        $definitions = [
            $childDefinition,
            new MappingDefinition(BenchmarkCollectionSource::class, BenchmarkCollectionTarget::class, [
                'children' => MapRule::from('children')->collection($elementSource, $elementTarget),
            ]),
        ];
        if ('structural' === $shape) {
            $definitions[] = new MappingDefinition(BenchmarkNestedSource::class, BenchmarkNestedTarget::class, [
                'child' => MapRule::from('child')->nested(BenchmarkChildTarget::class),
            ]);
        }

        foreach ([false, true] as $prepared) {
            $mode         = $prepared ? 'prepared' : 'default';
            $memoryBefore = \memory_get_usage();
            $mapper       = \createMapperAndCache($cacheDirectory, $definitions, $prepared, $provider, $transformers)['mapper'];
            $mapper->warmup();
            $results[$shape][$mode]['warmup_retained_memory_delta_bytes'] = \memory_get_usage() - $memoryBefore;

            foreach ([0, 1, 100, 1000] as $size) {
                $children = [];
                $expected = [];
                for ($index = 0; $index < $size; ++$index) {
                    $value      = 'child-' . $index;
                    $child      = new BenchmarkChildSource($value);
                    $children[] = 'structural' === $shape ? new BenchmarkNestedSource($child) : $child;
                    $target     = new BenchmarkChildTarget('transformer' === $shape ? \strtoupper($value) : $value);
                    $expected[] = 'structural' === $shape ? new BenchmarkNestedTarget($target) : $target;
                }
                $source    = new BenchmarkCollectionSource($children);
                $operation = static fn (): object => $mapper->map($source, BenchmarkCollectionTarget::class);
                if ($operation() != new BenchmarkCollectionTarget($expected)) {
                    throw new RuntimeException('Collection benchmark correctness check failed.');
                }
                $measurement                                               = \benchmark($operation, COLLECTION_ITERATIONS);
                $measurement['collections_per_second']                     = $measurement['operations_per_second'];
                $measurement['items_per_second']                           = \round($measurement['operations_per_second'] * $size, 1);
                $measurement['repeated_call_retained_memory_deltas_bytes'] = \benchmarkRetention($operation, COLLECTION_ITERATIONS);
                $results[$shape][$mode]['sizes'][$size]                    = $measurement;
                unset($operation);
            }
            unset($mapper);
        }
    }

    return $results;
}

/**
 * @param Closure(): object $operation
 *
 * @return array{
 *     median_ms: float,
 *     round_ms: list<float>,
 *     operations_per_second: float,
 *     median_user_cpu_ms: null|float,
 *     median_system_cpu_ms: null|float,
 *     median_cpu_ms: null|float,
 *     cpu_utilization_percent: null|float,
 *     cpu_ms_per_operation: null|float,
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
    $durations                = [];
    $userCpuDurations         = [];
    $systemCpuDurations       = [];
    $peakMemory               = 0;
    $peakMemoryUsed           = 0;
    $peakMemoryDeltas         = [];
    $retainedMemoryDeltas     = [];
    $peakMemoryUsedDeltas     = [];
    $retainedMemoryUsedDeltas = [];
    $operation();

    for ($round = 0; $round < ROUNDS; ++$round) {
        $usageBefore      = \resourceUsage();
        $memoryBefore     = \memory_get_usage(true);
        $memoryUsedBefore = \memory_get_usage();
        \memory_reset_peak_usage();
        $startedAt        = \hrtime(true);
        for ($index = 0; $index < $iterations; ++$index) {
            $operation();
        }

        $elapsed = (\hrtime(true) - $startedAt) / 1_000_000;
        // Snapshot before allocating CPU samples or growing the measurement arrays.
        $memoryAfter                 = \memory_get_usage(true);
        $memoryUsedAfter             = \memory_get_usage();
        $roundPeakMemory             = \memory_get_peak_usage(true);
        $roundPeakMemoryUsed         = \memory_get_peak_usage();
        $usageAfter                  = \resourceUsage();
        $durations[]                 = $elapsed;
        $peakMemory                  = \max($peakMemory, $roundPeakMemory);
        $peakMemoryUsed              = \max($peakMemoryUsed, $roundPeakMemoryUsed);
        $peakMemoryDeltas[]          = $roundPeakMemory - $memoryBefore;
        $retainedMemoryDeltas[]      = $memoryAfter - $memoryBefore;
        $peakMemoryUsedDeltas[]      = $roundPeakMemoryUsed - $memoryUsedBefore;
        $retainedMemoryUsedDeltas[]  = $memoryUsedAfter - $memoryUsedBefore;
        $userCpuDuration             = \resourceUsageDuration($usageBefore, $usageAfter, 'user_ms');
        $systemCpuDuration           = \resourceUsageDuration($usageBefore, $usageAfter, 'system_ms');
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
        'median_ms'                               => \round($median, 3),
        'round_ms'                                => $durations,
        'operations_per_second'                   => \round($iterations / ($median / 1_000), 1),
        'median_user_cpu_ms'                      => null === $medianUserCpu ? null : \round($medianUserCpu, 3),
        'median_system_cpu_ms'                    => null === $medianSystemCpu ? null : \round($medianSystemCpu, 3),
        'median_cpu_ms'                           => null === $medianCpu ? null : \round($medianCpu, 3),
        'cpu_utilization_percent'                 => null === $medianCpu ? null : \round($medianCpu / $median * 100, 1),
        'cpu_ms_per_operation'                    => null === $medianCpu ? null : \round($medianCpu / $iterations, 6),
        'peak_memory_bytes'                       => $peakMemory,
        'peak_memory_used_bytes'                  => $peakMemoryUsed,
        'median_peak_memory_delta_bytes'          => (int) \median($peakMemoryDeltas),
        'median_retained_memory_delta_bytes'      => (int) \median($retainedMemoryDeltas),
        'median_peak_memory_used_delta_bytes'     => (int) \median($peakMemoryUsedDeltas),
        'median_retained_memory_used_delta_bytes' => (int) \median($retainedMemoryUsedDeltas),
    ];
}

/**
 * @param list<MappingDefinition> $definitions
 *
 * @return array{
 *     median_ms: float,
 *     median_user_cpu_ms: null|float,
 *     median_system_cpu_ms: null|float,
 *     median_cpu_ms: null|float,
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
    $durations                = [];
    $userCpuDurations         = [];
    $systemCpuDurations       = [];
    $peakMemory               = 0;
    $peakMemoryUsed           = 0;
    $peakMemoryDeltas         = [];
    $retainedMemoryDeltas     = [];
    $peakMemoryUsedDeltas     = [];
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
            $usageAfter                  = \resourceUsage();
            $durations[]                 = (\hrtime(true) - $startedAt) / 1_000_000;
            $peakMemory                  = \max($peakMemory, \memory_get_peak_usage(true));
            $peakMemoryUsed              = \max($peakMemoryUsed, \memory_get_peak_usage());
            $peakMemoryDeltas[]          = \memory_get_peak_usage(true) - $memoryBefore;
            $retainedMemoryDeltas[]      = \memory_get_usage(true) - $memoryBefore;
            $peakMemoryUsedDeltas[]      = \memory_get_peak_usage() - $memoryUsedBefore;
            $retainedMemoryUsedDeltas[]  = \memory_get_usage() - $memoryUsedBefore;
            $userCpuDuration             = \resourceUsageDuration($usageBefore, $usageAfter, 'user_ms');
            $systemCpuDuration           = \resourceUsageDuration($usageBefore, $usageAfter, 'system_ms');
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
        'median_ms'                               => \round($median, 3),
        'median_user_cpu_ms'                      => null === $medianUserCpu ? null : \round($medianUserCpu, 3),
        'median_system_cpu_ms'                    => null === $medianSystemCpu ? null : \round($medianSystemCpu, 3),
        'median_cpu_ms'                           => null === $medianCpu ? null : \round($medianCpu, 3),
        'peak_memory_bytes'                       => $peakMemory,
        'peak_memory_used_bytes'                  => $peakMemoryUsed,
        'median_peak_memory_delta_bytes'          => (int) \median($peakMemoryDeltas),
        'median_retained_memory_delta_bytes'      => (int) \median($retainedMemoryDeltas),
        'median_peak_memory_used_delta_bytes'     => (int) \median($peakMemoryUsedDeltas),
        'median_retained_memory_used_delta_bytes' => (int) \median($retainedMemoryUsedDeltas),
    ];
}

/**
 * @return null|array{user_ms: float, system_ms: float}
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
 * @param null|array{user_ms: float, system_ms: float} $before
 * @param null|array{user_ms: float, system_ms: float} $after
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
