# sirix/object-mapper

[![Latest Stable Version](http://poser.pugx.org/sirix/object-mapper/v)](https://packagist.org/packages/sirix/object-mapper) [![Total Downloads](http://poser.pugx.org/sirix/object-mapper/downloads)](https://packagist.org/packages/sirix/object-mapper) [![Latest Unstable Version](http://poser.pugx.org/sirix/object-mapper/v/unstable)](https://packagist.org/packages/sirix/object-mapper) [![License](http://poser.pugx.org/sirix/object-mapper/license)](https://packagist.org/packages/sirix/object-mapper) [![PHP Version Require](http://poser.pugx.org/sirix/object-mapper/require/php)](https://packagist.org/packages/sirix/object-mapper)

`sirix/object-mapper` is a dependency-free mapper for explicit, trusted
`object -> object` boundaries. It compiles registered conventional mappings to
small PHP classes that use public source reads and a target's public
constructor.

## Installation

```sh
composer require sirix/object-mapper
```

The package requires PHP 8.2 or later and has no production dependencies.

## Register and map a pair

```php
use Sirix\ObjectMapper\Contract\ObjectMapperInterface;
use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Generator\MapperCache;
use Sirix\ObjectMapper\Generator\PhpMapperGenerator;
use Sirix\ObjectMapper\Metadata\MappingMetadataFactory;
use Sirix\ObjectMapper\Runtime\MappingRegistry;
use Sirix\ObjectMapper\Runtime\ObjectMapper;
use Sirix\ObjectMapper\Runtime\ValueTransformerRegistry;

$registry = new MappingRegistry([
    new MappingDefinition(UserResult::class, UserDto::class),
]);

$transformers = new ValueTransformerRegistry([
    new UuidToString(),
    new DateTimeToAtom(),
]);

/** @var ObjectMapperInterface $mapper */
$mapper = new ObjectMapper(
    $registry,
    new MapperCache(
        new MappingMetadataFactory($transformers, mappingRegistry: $registry),
        new PhpMapperGenerator(),
        __DIR__ . '/var/cache/object-mapper',
        generateOnDemand: false,
        valueTransformerRegistry: $transformers,
        mappingRegistry: $registry,
    ),
);

/** @var UserDto $dto */
$dto = $mapper->map($result, UserDto::class);
```

Register a pair once in application wiring. Mapping always uses the exact
runtime source class and requested target class; an unregistered pair raises
`MappingNotRegistered`.

## Optional Cycle ORM direct-proxy matching

Exact runtime-source matching remains the default. If an application has
explicitly registered a Cycle ORM entity pair and wants to accept Cycle's
runtime direct proxy for that entity too, opt in for that individual direct
definition:

```php
use Sirix\ObjectMapper\Definition\CustomMappingDefinition;
use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Definition\SourceMatchMode;

new MappingDefinition(
    Package::class,
    PackageDto::class,
    sourceMatch: SourceMatchMode::CycleProxy,
);

new CustomMappingDefinition(
    Package::class,
    PackageDto::class,
    $packageMapper,
    SourceMatchMode::CycleProxy,
);
```

This does not enable general inheritance or polymorphic mapping. It accepts a
concrete `Package` as usual, or only a value that implements Cycle's
`EntityProxyInterface` and whose immediate parent is exactly `Package`.
Normal subclasses, indirect proxies, and proxies for other entities remain
rejected. `ProviderCustomMappingDefinition` remains exact-only.

The package does not require Cycle ORM: the interface is detected at runtime
only when this opt-in is selected. Mapping does not preload relations or alter
Cycle lazy loading, so applications remain responsible for query preloading and
avoiding N+1 queries before mapping.

The source-match choice participates in generated-mapper cache identity.
The current development version uses format `7`; generated files remain
owner-only (`0600`). See [deployment instructions](#upgrading-generated-cache-to-format-7).

## Customize a conventional mapping

Keep DTOs independent of this package by defining exceptional source-member
selection at registration time. A `MapRule` takes precedence over the usual
same-name property/getter convention:

```php
use Sirix\ObjectMapper\Definition\MapRule;
use Sirix\ObjectMapper\Definition\MappingDefinition;

$definition = new MappingDefinition(
    UserResult::class,
    UserDto::class,
    rules: [
        'id' => MapRule::from('uuid'),
        'email' => MapRule::fromGetter('getPrimaryEmail'),
        'externalId' => MapRule::fromMethod('identifier')
            ->through(UuidToString::class),
        'createdAt' => MapRule::fromMethod('createdAt')
            ->through(DateTimeToAtom::class),
        'kind' => MapRule::constant('external'),
        'enabled' => MapRule::constant(true),
        'rank' => MapRule::constant(0),
        'note' => MapRule::constant(null),
    ],
    ignoredSource: ['passwordHash'],
);
```

`MapRule::from()` selects exactly a public, non-static, typed source property;
it never falls back to a getter. `MapRule::fromGetter()` selects exactly a
public, non-static, zero-argument `get*()` method with a declared return type.
`MapRule::fromMethod()` selects a deliberately named public, non-static,
zero-argument method with a declared return type; it does not extend
conventional method discovery. `through()` accepts exactly one registered
transformer class after a source selector. The transformer is checked during
warmup: its `transform()` method must have one typed required parameter and a
typed non-void result, and its input/output types must be compatible with the
source member and target parameter.

Transformer instances belong in one application-owned registry shared by the
metadata factory and cache. The registry uses exact runtime classes only: it
does not instantiate classes, resolve services, or consult a framework
container. Transformations are explicit and type-checked; the mapper never
performs implicit casts.

`MapRule::constant()` is a source-less terminal rule for trusted, fixed DTO
values. It accepts only `null`, `bool`, `int`, finite `float`, and `string`;
the value must be exactly compatible with the target constructor type during
warmup. Numeric strings, booleans, `null`, and integers are never coerced to a
different scalar type. A constant neither reads nor maps a same-named public
source property, so that property must still be selected by another rule or be
listed in `ignoredSource`.

Constants are compiled into generated cache PHP. Register only non-secret,
non-request-controlled application configuration: never put passwords, tokens,
ciphertext/plaintext, or personal data in a constant. `constant()` cannot be
combined with `through()`, `nested()`, or `collection()`. There is deliberately
no conditional rule, expression language, callback, property path, dynamic
method call, or service lookup; use a typed transformer for a trivial derived
value and an application-owned custom mapper for policy, authorization,
redaction, decryption, I/O, or stateful behavior.

The conventional `is*()` lookup remains available only for boolean target
parameters. Every public source property must be mapped or listed in
`ignoredSource`; an ignored name must still name an existing public property.

## Map nested DTOs and collections

Nested traversal is explicit and uses only an exact registered pair. Select a
source member, then configure exactly one terminal operation:

```php
new MappingDefinition(Session::class, SessionDto::class, rules: [
    'token' => MapRule::from('token')->nested(ApiAccessTokenDto::class),
    'releases' => MapRule::from('releases')->collection(
        Release::class,
        ReleaseDto::class,
    ),
]);
```

`nested()` requires the selected source member to be the exact registered
source class (or its nullable form). `collection()` deliberately takes both
element classes: PHP can verify that a member is `array`, but cannot recover an
array element type at runtime. The selected source and target parameter must
therefore be `array` or `?array`; iterables, generators, keyed output, PHPDoc
generic inference, and implicit scalar conversion are unsupported.

Collections preserve input order, discard input keys, and produce a
`list<ReleaseDto>`. A nullable nested member or collection maps `null` only to
`null`, never to an empty array. Nested conventional mappings are compiled as a
dependency graph during warmup; direct and indirect cycles are rejected before
traffic. A nested custom mapper is validated as an exact pair during warmup but
is not invoked until mapping executes.

If a collection element has the wrong runtime class, its diagnostic names the
target parameter, expected and actual class, and the input key without rendering
the element value. Integer keys are shown directly; string keys are represented
by a stable SHA-256 prefix and length, so secret or control-character keys do
not enter logs.

Conventional leaf elements (including transformer-backed leaves) use a
runtime-owned collection loop that resolves the declared child dependency once
per collection. Each attempted element executes behind an isolation barrier. Elements
are validated and mapped in input order, so callbacks for earlier valid items
still run before a later invalid item fails. Standalone generated mappers keep
their source-type checks. Children with nested or collection rules, and direct
or provider-backed custom children, retain the generated per-element dispatch
path; providers resolve once per attempted element invocation. Custom mapper
callbacks and provider resolution now run without an enclosing mapping's
declared dependency authority, preventing dispatch of enclosing-only siblings
or forged collection errors. This isolation covers nested mappings and
independent custom roots invoked from a callback, including outside collections.

This is an internal optimization: `ObjectMapper::map()` and
`NestedMappingRuntimeInterface` remain compatible. Generated code detects the
optional internal `CollectionMappingRuntimeInterface` capability and falls
back when it is absent. No public batch API or reusable bound executor handle
is introduced.

## Use a hand-written mapper

For policies outside safe member selection, construct and register an
application-owned mapper instance. No container or service lookup is involved:

```php
use Sirix\ObjectMapper\Contract\CustomObjectMapperInterface;
use Sirix\ObjectMapper\Definition\CustomMappingDefinition;

final class UserResultMapper implements CustomObjectMapperInterface
{
    public function map(object $source): object
    {
        assert($source instanceof UserResult);

        return new UserDto($source->uuid, $source->getPrimaryEmail());
    }
}

$registry = new MappingRegistry([
    new CustomMappingDefinition(UserResult::class, UserDto::class, new UserResultMapper()),
]);
```

Custom definitions have the same exact-pair registration and final target-type
check as generated mappers. `MappingDefinitionInterface` is a read and registry
contract, not an executable extension SPI: core dispatch supports only the
built-in conventional, direct-custom, and provider-custom definition types.
Use `CustomObjectMapperInterface` with `CustomMappingDefinition` for custom
execution. `warmup()` deliberately skips custom mappings and returns only the
generated conventional mapping keys, so a custom mapper is never executed as a
warmup side effect.

## Resolve an application-owned custom mapper at runtime

When an application custom mapper needs a service, register an opaque,
application-configured identifier instead of exposing a container to the core:

```php
use Sirix\ObjectMapper\Contract\CustomObjectMapperInterface;
use Sirix\ObjectMapper\Contract\CustomObjectMapperProviderInterface;
use Sirix\ObjectMapper\Definition\ProviderCustomMappingDefinition;

final class ApplicationMapperProvider implements CustomObjectMapperProviderInterface
{
    public function __construct(private CustomObjectMapperInterface $userResultMapper) {}

    public function get(string $mapperId): CustomObjectMapperInterface
    {
        // Resolve only a closed application allowlist of identifiers.
        return match ($mapperId) {
            'user-result' => $this->userResultMapper,
            default => throw new \LogicException('Unknown mapper identifier.'),
        };
    }
}

$provider = new ApplicationMapperProvider(new UserResultMapper(/* dependencies */));
$registry = new MappingRegistry([
    new ProviderCustomMappingDefinition(UserResult::class, UserDto::class, 'user-result'),
]);

$cache = new MapperCache(
    new MappingMetadataFactory($transformers, mappingRegistry: $registry),
    new PhpMapperGenerator(),
    __DIR__ . '/var/cache/object-mapper',
    $transformers,
    mappingRegistry: $registry,
    customObjectMapperProvider: $provider,
);
$mapper = new ObjectMapper($registry, $cache, customObjectMapperProvider: $provider);
```

Pass the same provider to `ObjectMapper` and `MapperCache`: root, nested, and
collection custom mappings then follow identical behavior. The identifier is an
opaque closed configuration value, never a class name or request-controlled
service key. The core neither instantiates it nor accesses a container. Provider
resolution happens once for each actual custom-mapping invocation; a resolved
mapper is not retained in mapping definitions, metadata, or generated files.

The application owns mapper service scope, recursive mapper use, I/O, and
policy. Keep authorization, decryption, and redaction explicit in the custom
mapper, and never derive mapper identifiers from request data. Provider and
mapper failures are intentionally reported only as a failed mapping pair.

## Cache warmup

Use a non-public **owner-only (`0700`)** cache directory. The deployment user
must warm it, and the runtime user must be the same owner so it can read the
generated owner-only (`0600`) files. Production keeps `generateOnDemand`
disabled and explicitly warms the registered mappings before traffic reaches
the release:

```php
use Sirix\ObjectMapper\Contract\WarmableObjectMapperInterface;

/** @var WarmableObjectMapperInterface $warmableMapper */
$warmableMapper = $mapper;
$warmableMapper->warmup();
```

`ObjectMapperInterface` is mapping-only so application-owned implementations
remain compatible. Request `WarmableObjectMapperInterface` only from deployment
or cache-warmup wiring that requires `warmup()`.

Warmup compiles every conventional pair and its conventional nested
dependencies in deterministic dependency order, and reports failures together.
Cycles and missing nested registrations fail warmup rather than recursing at
runtime. It is safe to run repeatedly. Development may set `generateOnDemand: true`; this is a local
convenience, not a substitute for CI/deployment warmup. Generated files are
locked, linted, atomically published, checked for safe owner-only permissions,
and ignored by Git. Do not place the cache in a shared or attacker-writable
directory. Deploy the transformer classes and application wiring first, then
warm the cache with the same registry that production will use. A transformer
signature or source-file change intentionally invalidates the generated mapper
cache; warm again after every such deployment. Constant values participate in
the same cache identity and are emitted as fixed literals, so re-warm after a
constant registration changes as well.

## Prepared cache for long-running workers

By default, each conventional mapping execution revalidates its metadata and
generated-cache key. This is the development-safe mode: changes to mapped
source, target, or transformer PHP files are detected by the normal cache-key
path in a live process.

For a production worker with a fixed, application-owned mapping registry, opt
in to reuse the prepared conventional mapping for the lifetime of each exact
`MappingDefinition` object:

```php
$cache = new MapperCache(
    new MappingMetadataFactory($transformers, mappingRegistry: $registry),
    new PhpMapperGenerator(),
    __DIR__ . '/var/cache/object-mapper',
    $transformers,
    generateOnDemand: false,
    mappingRegistry: $registry,
    reusePreparedMappings: true,
);
$mapper = new ObjectMapper($registry, $cache);

// Run once while the worker boots, before it accepts requests.
$mapper->warmup();
```

This opt-in avoids repeated reflection, source-file hashing, metadata
normalization, and generated-code rendering for a prepared definition. It is a
trust boundary: **live PHP code edits and registration changes are not detected
in an already running worker.** Deploy in this order:

```text
publish application code and trusted registrations
  -> start or restart every PHP worker
  -> construct the mapper with reusePreparedMappings: true
  -> warmup() successfully
  -> accept traffic
```

Restart or reload every worker whenever mapped source, target, transformer, or
mapping-registration code changes. Prepared entries are local to one worker;
they are neither shared nor synchronized between workers. Keep
`generateOnDemand: false` in production and do not defer first-time preparation
until traffic is being served.

The registry must be fixed application configuration. Do not put
`MappingDefinition` instances derived from request, tenant, or user input in a
shared long-running registry, and do not retain request state in definitions,
transformers, or custom mappers. Distinct dynamic definitions may retain
generated mappers for a worker lifetime. Custom and provider-custom mappings
continue to execute through their own runtime dispatch and are not prepared by
this option.

### Worker operations

- **FrankenPHP worker mode:** construct and warm the mapper during each worker
  boot, then gracefully restart workers after deployment. Use file watching
  only for local development, not as production invalidation.
- **RoadRunner:** warm during worker boot and reload the worker pool after a
  deployment (for example, `rr reset`).
- **Swoole/OpenSwoole:** publish the release first, then gracefully reload
  workers so every replacement worker boots and warms before serving requests.
  This mode is supported only for **non-yielding** mapping operations. A source
  getter, transformer, or custom mapper must not yield through coroutine I/O
  while mapping; the Fiber isolation coverage is for native PHP Fibers, not a
  Swoole/OpenSwoole coroutine-local context.

Mapping failures retain structured pair/parameter diagnostics. Do not add the
mapped source object or its values to application logs.

### Mapping execution contexts

The runtime keeps mapping state local to the current execution model: the main
PHP context has its own slot, and every native PHP `Fiber` has independent
state. Multiple Fibers may suspend and resume interleaved mappings without
sharing declared nested dependencies, collection diagnostics, or failure
provenance. A native Fiber may resume after a nested getter, transformer, or
custom mapper yields, and may map again after either success or a caught
failure.

Every public `MapperCache::map()` call is an independent execution boundary,
including recursive calls from application callbacks. Nested dispatch can use
only dependencies declared by its active mapping frame. Frames restore the
previous execution in `finally`, including when preparation, a callback, or
generated mapping code throws. A completed mapping does not retain its source,
mapped objects, caught exception, or request-scoped collaborator; Fiber-owned
runtime state does not make an earlier failure valid in a later mapping on the
same Fiber.

This remains an internal runtime implementation detail. `ObjectMapper::map()`,
`NestedMappingRuntimeInterface`, generated format `7`, and prepared-cache
ownership/lifecycle are unchanged. Native Fiber coverage does not extend the
non-yielding Swoole/OpenSwoole restriction described above.

Conventional mappings without nested or collection rules now use lightweight
execution at the root and when reached as leaves. Eligibility is recorded during
preparation; warm prepared calls do not rescan metadata for this classification.
Getters, transformers, and target constructors run behind an isolation barrier
without access to enclosing dependencies or collection diagnostics. Replayed
parent errors are sanitized, including errors whose provenance was not yet
consumed. Public APIs, generated format `7`, prepared-cache ownership and worker
lifecycle, and default source-file invalidation remain unchanged.

### Benchmarking prepared mappings

Run the included benchmark after dependency installation:

```sh
php tools/benchmark.php
```

It emits JSON with the median of five rounds for throughput, wall-clock time,
current-process user/system CPU time, and PHP-memory deltas. CPU time excludes
child processes, so do not use the cold-first result as a total deployment CPU
measurement: it includes generated-file linting in a child PHP process.

For release `0.8.0`, an illustrative run on this project's PHP 8.2 CLI
environment (OPcache CLI and JIT disabled) after warmup produced:

| Scenario | Default | Prepared | CPU time per mapping |
| --- | ---: | ---: | ---: |
| Simple DTO | 5,064 ops/s | 612,920 ops/s | 0.1966 ms → 0.00162 ms |
| Nested DTO | 1,684 ops/s | 213,770 ops/s | 0.5823 ms → 0.00468 ms |
| Collection of 100 DTOs | 1,534 ops/s | 7,118 ops/s | 0.6509 ms → 0.1404 ms |

The hot-path runs retained no additional PHP allocator memory between rounds.
Run the benchmark on target hardware and compare ratios rather than treating
these absolute values as a production capacity guarantee.

### Benchmarking execution contexts

The benchmark also has execution-context workloads for flat, deep nested,
diamond, collection, native-Fiber, and expected-failure paths. Each reports
out-of-loop correctness checks plus repeated-call PHP and allocator-retention
deltas; the Fiber workload constructs and warms the mapper before timing
suspend/resume operations. `--runtime-root` selects the library source to
measure while keeping this physical harness fixed; it is fail-closed: a missing
or mismatched selected runtime class aborts the benchmark instead of falling
back to the harness checkout. The JSON `runtime_isolation` record verifies the
loaded class provenance; all final JSON reported `verified` with 15 class-file
records. Use the selected root for both revisions.
`--execution-context-mode=prepared`, `default`, and `both` select the prepared
cache, ordinary cache, or both modes respectively (`both` is the default).

For the canonical prepared-cache comparison, run the harness from one fixed
checkout and substitute each revision's absolute source root. Keep
`XDEBUG_MODE=off`, CPU affinity, PHP settings, iterations, and rounds identical:

```sh
XDEBUG_MODE=off taskset -c 2 php -d pcov.enabled=0 -d opcache.enable_cli=1 -d opcache.jit=disable tools/benchmark.php --runtime-root=/absolute/path/to/baseline --workload=contexts --execution-context-mode=prepared --simple-iterations=200000 --collection-iterations=5000 --context-iterations=30000 --fiber-iterations=30000 --rounds=7
XDEBUG_MODE=off taskset -c 2 php -d pcov.enabled=0 -d opcache.enable_cli=1 -d opcache.jit=disable tools/benchmark.php --runtime-root=/absolute/path/to/candidate --workload=contexts --execution-context-mode=prepared --simple-iterations=200000 --collection-iterations=5000 --context-iterations=30000 --fiber-iterations=30000 --rounds=7
```

Use `--workload=flat`, `collection`, `deep`, `diamond`, `fiber`, or `failure`
to repeat one shape. `--workload=collections` remains the legacy collection
matrix selector; it is not the execution-context collection shape. Compare
alternating revision runs rather than a single absolute timing, and keep setup,
warmup, source construction, and correctness checks outside the measured loop.
JSON records effective PHP settings, wall and current-process CPU time, and
memory measurements; child-process CPU time is not included.

Current behavioral verification covers suspended/resumed native-Fiber nested
getter, transformer, and custom-mapper calls; independent main-context work
while a Fiber is suspended; public-cache reentry after execution and partial
preparation failures; failure provenance; and weak-reference cleanup. The
focused scenarios passed 24 tests / 558 assertions, and the integration suite
passed 181 tests / 2,543 assertions. The final complete suite passed 267 tests /
2,876 assertions.

The verified comparison uses one physical harness (SHA-256
`0d5e7d12563c9a5e35514291cb3257cc71423bccfdb6a59fda2da002d6747497`) and
baseline `d0ca7c4` (runtime hash `2a802440...f5c92e`) against the candidate
(runtime hash `2c74db...f4c0e`). It ran on PHP 8.5.8 CLI, pinned to CPU 2, with
OPcache on, JIT disabled, PCOV false, and `XDEBUG_MODE=off`. Seven prepared
rounds had medians of roughly 73–281 ms. The paired ratios below are
baseline-ms/candidate-ms, so values above 1 favour the candidate:

| Workload | A/B | C/D |
| --- | ---: | ---: |
| Flat | 1.086 | 1.045 |
| Collection | 1.149 | 1.101 |
| Deep | 1.122 | 1.043 |
| Diamond | 1.114 | 0.890 |
| Native Fiber | 1.115 | 1.061 |
| Leaf expected failure | 0.974 | 0.963 |
| Structural expected failure | 0.950 | 0.962 |

All repeated-call retention and allocator deltas were zero. OPcache-on
single-workload controls confirmed collection (1.121/1.173), deep
(1.053/1.225), and diamond (1.114/1.063). The fail-closed OPcache-off balanced
A/B/B/A repeat confirmed deep (1.097/1.062) and diamond (1.108/1.105).

Fiber is inconclusive because it was sensitive to order and run frequency: the
focused A/B/A/B result was 0.841/0.878, while reverse B/A/A/B measured
baseline/candidate as 1.093/1.088. It therefore supports neither a Fiber gain
nor a Fiber regression. The small expected-failure losses overlap run-to-run
variation in an exception-dominated path and are not claimed as an improvement.

The low-duration default controls (seven rounds; simple 2,000 and
collection/context/Fiber 500 iterations) varied strongly and their ratios
changed sign. That path includes unchanged preparation, cache, and source
validation, so it cannot attribute a regression to execution contexts. The
earlier blocked result used only 100 context/Fiber and 10 collection iterations,
producing 0.1–2.3 ms prepared rounds while the documented protocol required
10,000 context iterations; it published only those noisy default medians.

The performance gate passes on repeatable prepared deep, diamond, and
collection gains plus zero retention; the remaining workloads showed no
unexplained regression beyond measured variation. These microbenchmarks do not
make an FPM throughput or capacity claim; repeat them on deployment hardware.

### Benchmarking lightweight leaf execution

This comparison uses baseline `81845e5`, which already includes the collection
optimization below. Baseline runs preloaded its original `MapperCache` class;
both revisions ran the same physical fixture/harness file (SHA-256
`1384b9374b8287ceb3a82a398027828b084ad82335f9307628d40db8f31c320e`).
Measurements used PHP 8.5.8 CLI on an Intel Core Ultra 5 135U under Microsoft
virtualization, pinned to CPU 2 with `taskset`, OPcache on/off, JIT/PCOV off,
and no active Xdebug modes.

Each workload ran five rounds in baseline/candidate/candidate/baseline (ABBA)
order, with 10,000 iterations for each leaf workload and 100 for collections:

```sh
taskset -c 2 php -d pcov.enabled=0 -d opcache.enable_cli=1 -d opcache.jit=disable tools/benchmark.php --workload=flat --iterations=10000 --rounds=5
taskset -c 2 php -d pcov.enabled=0 -d opcache.enable_cli=1 -d opcache.jit=disable tools/benchmark.php --workload=collections --iterations=100 --rounds=5
```

Repeat the first command with `--workload=getter-transformer` and
`--workload=nested`, and both with `-d opcache.enable_cli=0`. Collections cover
sizes 0, 1, 100, and 1000 in default/prepared modes. An extra nested ABBA run used
10,000 iterations; prepared collection followups used 20,000 empty-collection
calls and 300 calls at size 1000, including rapid single-workload comparisons.
Startup, warmup, source construction, and correctness checks were outside timing.

| Prepared workload | Paired candidate/baseline throughput |
| --- | ---: |
| Flat DTO, OPcache on/off | 1.97–2.47× |
| Getter/transformer leaf, OPcache on/off | 1.88–2.67× |
| Nested leaf, OPcache on (initial and followup) | 1.19–1.33× |
| 1000-item collection controls, OPcache on (rapid followups) | 1.007–1.037× |

Empty prepared custom collections with OPcache off showed an accepted tradeoff:
0.953×/0.958× throughput, or +0.153/+0.131 µs per call. Added root dispatch/setup
is a plausible explanation, but its causal cost was not measured. Other control
timings were noisy: the initial default nested slowdown did not repeat, and
identical-code provider runs varied from 0.909× to 1.076×. No per-element
regression beyond that variability was established. These CLI results imply
neither zero overhead nor production/FPM capacity.

All repeated-call retention probes reported zero growth. Prepared flat/getter
peak used-memory deltas fell from 1808/1800 to 96/88 bytes, with unchanged
prepared warmup retention; flat CPU time per 10,000 calls fell from 16.0–17.0
to 6.6–8.3 ms. CPU measurements cover only the current PHP process.
Default warmup retention matched in every pair except custom collections with
OPcache off: 14,416 → 79,952 bytes in both pairs (+65,536 bytes during warmup,
with no repeated-call growth); all prepared warmup retention matched.

### Benchmarking collection execution

The collection workloads cover sizes 0, 1, 100, and 1000 for conventional
leaves, structural children, transformer-backed leaves, direct custom mappers,
and provider-backed custom mappers, in default and prepared-cache modes:

```sh
php -d pcov.enabled=0 -d opcache.enable_cli=1 -d opcache.jit=disable tools/benchmark.php --workload=collections --iterations=100 --rounds=5
```

JSON includes collections/s, items/s, per-round timings, CPU and memory
measurements, and retained-memory checks across repeated calls. Repeat with
`-d opcache.enable_cli=0` for the non-OPcache comparison. An empty collection
has zero items/s; use collections/s to compare its overhead.

Disable coverage and profiling uniformly in both revisions, including PCOV
and any active Xdebug modes. Instrumenting only the workspace source can make
an archived baseline appear faster even with otherwise identical PHP settings.

The comparison baseline for this collection change is revision `38f002c` with
the same updated benchmark harness. Both revisions must load the exact same
physical benchmark fixture file from the same storage: default-cache mode
hashes source files, so comparing a temporary copy with a workspace file can
distort the ratio. Select each revision's library source through its autoloader
while keeping the benchmark fixture path fixed. These historical collection
measurements predate lightweight leaf execution; they are not incremental gains
over it. They also predate the execution-context runtime work, so they are not
an incremental comparison for that change. See the [separate leaf
comparison](#benchmarking-lightweight-leaf-execution) for the subsequent change
against `81845e5`.

On PHP 8.5.8 CLI, Linux x64, with OPcache on, JIT/PCOV off and no active
Xdebug, two alternating baseline/candidate runs of five rounds each produced
the following ranges of run medians for collections of 1000 items. Both
revisions used the same physical workspace fixture file.

| Scenario | Baseline collections/s | Candidate collections/s |
| --- | ---: | ---: |
| Prepared leaf | 579–628 | 2,582–3,308 |
| Default leaf | 184–508 | 1,547–1,838 |
| Prepared transformer | 457–588 | 1,794–2,193 |
| Prepared structural | 302–338 | 288–376 |
| Prepared custom | 1,481–1,587 | 918–1,251 |
| Prepared provider | 1,322–1,344 | 804–1,099 |

Multiply by 1000 for items/s: prepared leaves reached 2.582–3.308 million
items/s versus 0.579–0.628 million, a 4.46–5.27× gain in paired run medians.
For the 1000-item prepared leaf, default leaf and prepared transformer
workloads, all ten candidate timings beat all respective baseline timings;
even the slowest candidate beat the fastest baseline
by 3.12× for prepared leaves, 2.82× for default leaves and 2.60× for prepared
transformers. Small conventional collections showed no repeatable slowdown;
structural timing ranges overlapped.

Prepared custom/provider throughput was 18–39% lower in paired comparisons.
These paths now enforce the custom-callback isolation described above, which
the baseline lacked. This is an explicit security/performance tradeoff, but
the measurement does not isolate the barrier's cost: substantial host noise
also affected unchanged controls. Repeat measurements on deployment hardware.

All 160 workload/run combinations showed zero retained-memory growth in both
repeated-call blocks. Default warmup retained 162,208 bytes versus 161,008
(+1,200 bytes); incremental prepared warmup remained 20,136 bytes. All 40
workloads also passed a five-round OPcache-off smoke run; no OPcache-off
speedup is claimed.

## Upgrading generated cache to format 7

The collection execution change bumps generated-mapper cache format from `6`
to `7`. Format-6 files are not reused. Deploy the application code and trusted
registrations, rotate the previous cache directory, and warm the new
owner-only (`0700`) cache as the runtime owner before serving traffic. Generated
files remain `0600`. With `generateOnDemand: false`, deployment must complete
warmup successfully before requests reach the release.

Restart or reload every long-running PHP worker so it loads the new runtime
and generated mappers; this is required for workers using prepared-mapping
reuse too. Do not mix an old runtime with newly generated format-7 code.

## Upgrading to 0.8.0

Create one `MappingRegistry` during application wiring and pass that same
instance to both `MappingMetadataFactory` and `MapperCache` for structural
rules. Existing transformer wiring remains shared in the same way. Direct
`CustomMappingDefinition` registrations are unchanged. For provider-backed
definitions, additionally pass the same optional provider to `ObjectMapper`
and `MapperCache`, as shown above. `warmup()` skips every custom mapping,
including provider-backed children: it neither resolves a provider nor creates
or executes a custom mapper.

Release `0.8.0` retains generated-mapper cache format `6`; no generated-cache
or cache-identity migration is required. Deploy the updated code and trusted
registrations, then warm the owner-only (`0700`) cache as the runtime owner
before serving traffic.

## Mapping rules and guarantees

- Source and target must be existing concrete classes and each pair is unique.
  Every built-in definition rejects a `class_alias()` value: registrations must
  use the exact canonical name returned by `ReflectionClass::getName()`.
  Conventional `MappingDefinition` pairs must additionally use named concrete
  classes; direct and provider-backed custom definitions may use anonymous
  concrete classes.
- The target needs a public constructor. Values are passed by named argument.
- A target parameter resolves, in order, from a public non-static property,
  public zero-argument `getX()`, or boolean-only `isX()` method.
- Required source and target declarations must be type-compatible. Untyped
  source values, narrowing `mixed`, nullability violations, and unsupported
  access fail before generated code is loaded.
- Generated mappers read only validated source members and fixed validated
  constant literals; they never use reflection writes, magic access, or
  `eval()`.
- Mapping exceptions identify the pair, target parameter, or configured
  selector needed for diagnosis. They do not include mapped values; do not add
  source objects containing sensitive data to application logs.

## Non-goals

This is not a serializer or a mapper for untrusted HTTP/JSON input. It has no
automatic casts, implicit nested/collection traversal, reverse mapping,
mapping into existing objects, framework/container integration, source-side
attributes, or generic container access. Provider-backed custom mappers use a
narrow, application-owned opaque-ID contract; a framework bridge, allowlist,
and any PSR-11/container integration remain outside this package. `iterable`, `Traversable`,
generators, PHPDoc element inference, keyed collection output, and scalar
element conversion are intentionally excluded. Transformations are limited to explicitly
registered, type-checked `through()` rules; they are not a general expression,
callback, conditional, closure, property path, dynamic method, or
service-resolution mechanism. Use hand-written mappers for policies outside
these trusted mapping boundaries.
