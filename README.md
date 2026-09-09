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
Release `0.9.0` uses format `7`; generated files remain
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

Conventional leaf elements (including transformer-backed leaves), direct custom
mappers, and provider-backed custom mappers use a
runtime-owned collection loop that resolves the declared child dependency once
per collection. Each attempted element executes behind an isolation barrier. Elements
are validated and mapped in input order, so callbacks for earlier valid items
still run before a later invalid item fails. Standalone generated mappers keep
their source-type checks. Conventional children with nested or collection rules
retain the generated per-element dispatch path. Custom collections bind the
definition once, validate each source and returned target, and resolve providers
once per attempted element invocation. Custom mapper
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

For release `0.9.0`, a seven-round run after warmup produced the following
medians. The run used PHP 8.5.8 CLI on an
Intel Core Ultra 5 135U, pinned to one CPU; OPcache CLI was enabled, while JIT,
PCOV, and Xdebug were disabled:

| Scenario | Default | Prepared | CPU time per mapping |
| --- | ---: | ---: | ---: |
| Simple DTO | 8,814 ops/s | 1,631,152 ops/s | 0.113335 ms → 0.000614 ms |
| Nested DTO | 3,220 ops/s | 346,640 ops/s | 0.312951 ms → 0.002886 ms |
| Collection of 100 DTOs | 3,029 ops/s | 38,459 ops/s | 0.330580 ms → 0.026020 ms |

Reproduce this table with:

```sh
XDEBUG_MODE=off taskset -c 2 php -d pcov.enabled=0 -d opcache.enable_cli=1 -d opcache.jit=disable tools/benchmark.php --workload=legacy --rounds=7
```

The hot-path runs retained no additional PHP allocator memory between rounds.
Run the benchmark on target hardware and compare ratios rather than treating
these absolute values as a production capacity guarantee.

### Benchmarking execution contexts

Release `0.9.0` includes workloads for flat, deep nested, diamond, collection,
native-Fiber, and expected-failure paths. Each reports correctness checks
outside timing and repeated-call PHP and allocator-retention deltas.
The Fiber workload constructs and warms the mapper before timing suspend/resume
operations.

Use the `0.9.0` harness from one fixed checkout to compare `0.8.0` and `0.9.0`.
`--runtime-root` selects the library source while keeping the physical harness
and fixtures identical. A missing or mismatched runtime class aborts the run;
the JSON `runtime_isolation` record verifies which source tree was loaded.
`--execution-context-mode=prepared`, `default`, or `both` selects the cache
mode (`both` is the default). Keep PHP settings, CPU affinity, iterations,
and rounds identical across versions:

```sh
XDEBUG_MODE=off taskset -c 2 php -d pcov.enabled=0 -d opcache.enable_cli=1 -d opcache.jit=disable tools/benchmark.php --runtime-root=/absolute/path/to/0.8.0 --workload=contexts --execution-context-mode=prepared --simple-iterations=200000 --collection-iterations=5000 --context-iterations=30000 --fiber-iterations=30000 --rounds=5
XDEBUG_MODE=off taskset -c 2 php -d pcov.enabled=0 -d opcache.enable_cli=1 -d opcache.jit=disable tools/benchmark.php --runtime-root=/absolute/path/to/0.9.0 --workload=contexts --execution-context-mode=prepared --simple-iterations=200000 --collection-iterations=5000 --context-iterations=30000 --fiber-iterations=30000 --rounds=5
```

Use `--workload=flat`, `collection`, `deep`, `diamond`, `fiber`, or `failure`
to repeat one shape. `--workload=collections` selects the separate collection
matrix. Alternate versions across runs, and keep setup, warmup, source
construction, and correctness checks outside the measured loop.

Release `0.9.0` passed 271 tests / 3,014 assertions on PHP 8.2–8.5. Coverage
includes suspended/resumed getters, transformers and custom mappers, failed
provider lookups, independent main-context work, public-cache reentry, partial
preparation failures, failure provenance, and weak-reference cleanup.
Native Fiber verification does not establish Swoole coroutine support.

### Benchmarking lightweight leaf execution

Release `0.9.0` avoids structural execution tables for conventional mappings
without nested or collection rules. Prepared calls reuse the classification
recorded during preparation; getters, transformers and constructors retain
their isolation and exception guarantees.

Use `--workload=flat`, `getter-transformer`, or `nested` with the same harness
and each version's `--runtime-root` to compare these workloads with `0.8.0`.
The [prepared-mapping table](#benchmarking-prepared-mappings) reports absolute
`0.9.0` measurements; the [collection comparison](#benchmarking-collection-execution)
below reports throughput ratios against `0.8.0`.

### Benchmarking collection execution

The collection matrix covers sizes 0, 1, 100, and 1000 for conventional leaves,
structural children, transformer-backed leaves, direct custom mappers, and
provider-backed custom mappers, in default and prepared-cache modes:

```sh
XDEBUG_MODE=off taskset -c 2 php -d pcov.enabled=0 -d opcache.enable_cli=1 -d opcache.jit=disable tools/benchmark.php --runtime-root=/absolute/path/to/0.8.0 --workload=collections --iterations=200 --rounds=5
XDEBUG_MODE=off taskset -c 2 php -d pcov.enabled=0 -d opcache.enable_cli=1 -d opcache.jit=disable tools/benchmark.php --runtime-root=/absolute/path/to/0.9.0 --workload=collections --iterations=200 --rounds=5
```

JSON includes collections/s, items/s, per-round timings, CPU and memory
measurements, and retained-memory checks. An empty collection has zero items/s;
use collections/s to compare its overhead. Repeat with `-d opcache.enable_cli=0`
for a non-OPcache comparison. Disable coverage and profiling uniformly.

Both versions must load the same physical fixture file: default-cache mode
hashes source files, so comparing different fixture locations can distort
results.

A focused comparison used PHP 8.5.8 CLI, pinned to CPU 2, with OPcache on and
JIT/PCOV/Xdebug off. One physical harness copy was restricted to one shape,
prepared mode, and 1000 elements. Each run measured five rounds of 1000
collections; the version comparison used `0.9.0 / 0.8.0 / 0.8.0 / 0.9.0` order.

| Prepared collection, 1000 elements | 0.8.0 collections/s | 0.9.0 collections/s | 0.9.0 / 0.8.0 throughput |
| --- | ---: | ---: | ---: |
| Conventional leaf | 757–773 | 4,139–4,194 | 5.351–5.543× |
| Direct custom | 1,758–1,763 | 3,962–4,055 | 2.247–2.307× |
| Provider custom | 1,545–1,563 | 3,009–3,063 | 1.947–1.960× |

Ratios compare paired run medians; they are not confidence intervals or
application speedups. Multiply collections/s by 1000 to obtain items/s.
All repeated-call retention probes were zero. The full 40-workload matrix
also passed its correctness and retention checks, but variable control timings
make it less suitable for attributing small performance differences.

To repeat the focused comparison, use one frozen copy of `tools/benchmark.php`
for both versions and restrict `benchmarkCollections()` to one shape (`custom`,
`provider`, or `leaf`), prepared mode, and size 1000; use
`--iterations=1000 --rounds=5`. Keep its autoloader pointed at the installed
dependencies and retain the same physical fixture path across runs.

These CLI measurements do not establish FPM capacity or an OPcache-off speedup.
Repeat representative workloads on deployment hardware with the real providers
and custom mappers used by the application.

### Custom/provider collection follow-up

In `0.9.0`, custom collections bind the validated definition once and execute
through the existing custom executor inside an isolated per-element boundary.
Provider lookup remains per element; returned targets are checked before
proceeding to the next item. Source matching, callback order, and error
sanitization are preserved. The [version comparison above](#benchmarking-collection-execution)
includes this optimization when comparing `0.9.0` with `0.8.0`.

## Upgrading generated cache to format 7

Upgrading from `0.8.0` to `0.9.0` changes generated-mapper cache format from `6`
to `7`. Format-6 files are not reused. Deploy the application code and trusted
registrations, rotate the previous cache directory, and warm the new
owner-only (`0700`) cache as the runtime owner before serving traffic. Generated
files remain `0600`. With `generateOnDemand: false`, deployment must complete
warmup successfully before requests reach the release.

Restart or reload every long-running PHP worker so it loads the new runtime
and generated mappers; this is required for workers using prepared-mapping
reuse too. Do not mix an old runtime with newly generated format-7 code.

## Upgrading to 0.9.0

Create one `MappingRegistry` during application wiring and pass that same
instance to both `MappingMetadataFactory` and `MapperCache` for structural
rules. Existing transformer wiring remains shared in the same way. Direct
`CustomMappingDefinition` registrations are unchanged. For provider-backed
definitions, additionally pass the same optional provider to `ObjectMapper`
and `MapperCache`, as shown above. `warmup()` skips every custom mapping,
including provider-backed children: it neither resolves a provider nor creates
or executes a custom mapper.

Release `0.9.0` preserves public mapping interfaces and application wiring,
but changes generated-mapper cache format from `6` in `0.8.0` to `7`.
Follow the [cache migration instructions](#upgrading-generated-cache-to-format-7):
deploy the updated code and registrations, warm a new owner-only (`0700`) cache,
and restart or reload long-running workers before serving traffic.

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
