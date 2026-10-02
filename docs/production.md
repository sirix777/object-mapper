# Production, trust, and lifecycle

This page collects the operational detail behind the guarantees summarized in
the [README](../README.md). It describes the intended production wiring, the
trust boundary, cache lifecycle, invalidation, platform limits, and benchmark
methodology. Public APIs are unchanged; only this documentation is new.

## Trust model

The package distinguishes three different concerns that are easy to conflate:

1. **Data validation.** Source/target signatures, selectors, types, nullability,
   constants, and collection element classes are checked before generated code
   is loaded. This rejects unsafe or ambiguous mappings.
2. **Accidental reentry and context isolation.** Native Fibers, recursive public
   `map()` calls, and nested/collection dispatch keep independent declared
   dependencies, diagnostics, and failure provenance.
3. **Application code trust.** Registered definitions, transformers, custom
   mappers, and provider implementations are trusted PHP code running in the same
   process.

The library can enforce the first two. It cannot sandbox the third: generated
diagnostic provenance protects a specific diagnostic authority, it is **not** a
sandbox. A hostile or buggy application callback still executes with the process'
own authority. Do not treat the mapper as a security boundary between mutually
untrusted PHP components.

Keep untrusted or request-controlled data out of registrations, constants,
transformer classes, provider identifiers, and generated cache PHP.

## Production wiring

- Build **one shared `MappingRegistry`** and pass the same instance to
  `MappingMetadataFactory` and `MapperCache`. Structural rules resolve nested
  dependencies through this registry.
- Build **one shared `ValueTransformerRegistry`** and pass the same instance to
  the metadata factory and the cache.
- For provider-backed custom mappings, pass **one application-owned provider**
  (or one shared `CustomMappingExecutor`) to both `ObjectMapper` and
  `MapperCache`, so root, nested, and collection custom mappings behave
  identically.
- Use `generateOnDemand: false` in production and `reusePreparedMappings: true`
  for a fixed, application-owned registry. Warm before traffic.
- Keep registrations fixed application configuration. Never derive them from
  request, tenant, or user input.

### Custom providers and warmup

`warmup()` compiles conventional pairs and their conventional nested
dependencies. It does **not** resolve a provider, instantiate a mapper, or
execute a custom mapping. A provider-backed definition is validated as an exact
pair, but the provider is only consulted when the mapping actually executes
(once per attempted custom invocation). Warmup therefore cannot surface a broken
provider service; verify provider wiring in application startup or a smoke test
instead.

## Cache directory lifecycle

- The cache directory must be a non-public **owner-only `0700`** directory.
  Generated files are owner-only **`0600`**. Never relax these permissions.
- The **warmup user and the runtime user must be the same OS owner**, otherwise
  the runtime cannot read the generated files.
- Use a **release-specific cache directory** (for example a path containing the
  release identifier or a deployment timestamp). Warming into a fresh directory
  avoids mixing generated files from two runtimes.
- After a deployment is live and all old workers have stopped, delete only the
  previous, no-longer-used release cache directory. Never delete or rewrite a
  cache directory that a running release still uses.
- Do not place the cache in a shared, network, or attacker-writable directory.

Generated files are written to a temporary file, linted in a child PHP process,
atomically renamed into place, and compared byte-for-byte against the expected
content before loading. The cache directory is checked for symlinks and
owner-only directory permissions, generated PHP files are checked for safe
owner-only permissions, and a lock path is rejected if it is a symlink (lock file
permissions themselves are not checked). Content comparison is a defense in
depth and does not replace a safe, owner-only filesystem.

## Warmup and deployment order

```text
publish application code and trusted registrations
  -> create a fresh owner-only (0700) release cache directory
  -> run CLI warmup as the runtime owner (generateOnDemand: false)
  -> start or restart every PHP worker
  -> construct the mapper (reusePreparedMappings: true for workers)
  -> warm again during worker boot if the worker prepares lazily
  -> accept traffic
```

CLI warmup uses the same registry, transformers, and provider wiring as the
runtime. `ObjectMapperInterface` is mapping-only; request the warmable interface
only from deployment or worker-boot wiring.

## Platform limits

- **FPM and other request SAPIs.** The first-preparation cost (reflection, file
  hashing, code generation) is paid when a new `MapperCache` instance is built.
  In a typical FPM setup each request builds its objects from scratch, so this
  cost recurs per request, and prepared mappings stored on the cache instance do
  not survive the request. There is no verified on-demand generation path under
  FPM. Use CLI warmup with `generateOnDemand: false`. When the generated file was
  already published by CLI warmup, the runtime still renders the expected code as
  a string for the content comparison, but it neither publishes the file again
  nor spawns the lint child process.
- **Linting uses a child process.** Generated files are validated with
  `PHP_BINARY -l` through `exec()`. The deployment environment must allow
  process execution, and `PHP_BINARY` is the PHP binary of the current runtime,
  not necessarily the CLI executable you expect. The library deliberately offers
  no `PHP_BINARY` selection API. There is a small per-generated-file cost for
  this child process, which is why it belongs in warmup, not in request paths.
- **Swoole / OpenSwoole.** Supported only for **non-yielding** mapping
  operations. A getter, transformer, or custom mapper must not yield through
  coroutine I/O while mapping. The Fiber isolation coverage is for native PHP
  Fibers, not a coroutine-local context.
- **FrankenPHP worker mode / RoadRunner.** Warm during worker boot and reload
  the pool after deployment. Use file watching only for local development.

## Worker lifecycle and prepared mappings

`reusePreparedMappings: true` avoids repeated reflection, source-file hashing,
metadata normalization, and code rendering for each exact `MappingDefinition`
object. It is a trust boundary: **live PHP edits and registration changes are not
detected in an already running worker.** Restart or reload every worker whenever
mapped source, target, transformer, or registration code changes. Prepared
entries are local to one worker and are not shared or synchronized. Custom and
provider-custom mappings are not prepared by this option.

For a **fixed built-in `MappingRegistry`** (not a third-party
`MappingRegistryInterface` implementation), prepared mode also reuses the
compiled structural scope tables of a definition between roots: the first
structural root builds and validates the nested/collection bindings, and later
roots reuse them without re-reading the registry. A custom or wrapped registry
keeps validating its compiled bindings on every prepared root, so swapping a
child definition or source-match mode is still rejected. Scope reuse stores only
inert binding tables; it never retains a source, target result, execution frame,
or provenance between roots.

Do not create `MappingDefinition` objects from request/tenant/user input, and do
not retain request state in definitions, transformers, or custom mappers.
Distinct dynamic definitions can retain generated mappers for a worker lifetime.

## Invalidation guarantees

There are two identity levels.

**Root generated cache.** A conventional root definition is cached under a key
derived from its normalized metadata: the generated format version, the exact
source and target canonical class names, the source-match mode, the source and
target file hashes, and each target parameter's normalized data (name, default
availability, exported target type, source member kind/name/selection and
exported type, transformer class and exported input/output types plus the
transformer file hash, nested/collection operation and element classes plus the
dependency fingerprint, and constant identity).

**Dependency fingerprints.** `ignoredSource`, per-rule selectors, and the
custom/provider identity (mapper class, mapper file hash, `map()` method file
hash, or the opaque provider identifier) participate in a parent's
per-parameter dependency fingerprint when that registration is used as a nested
dependency of a conventional parent. A root custom or provider definition has no
generated cache file at all.

A reflected signature change that is actually captured by normalized metadata
(for example a constructor parameter name or type, nullability, a source
member's kind or declared type, or a transformer's input/output types) produces
a new root key. A change to a directly hashed file (source, target, transformer,
or custom mapper method file) also produces a new key. This is a **limited
guarantee**: changes that are not reflected into these fields — such as an
arbitrary parent or trait method body that is not the directly hashed file — may
**not** produce a new cache key. The package does not recursively hash
parent/trait files. Do not rely on hot reload of already-declared classes;
restart after every deployment.

## Execution contexts

The runtime keeps mapping state local to the current execution model: the main
PHP context has its own slot, and every native PHP `Fiber` has independent
state. Multiple Fibers may suspend and resume interleaved mappings without
sharing declared nested dependencies, collection diagnostics, or failure
provenance. Every public `MapperCache::map()` call is an independent execution
boundary, including recursive calls from application callbacks. A completed
mapping does not retain its source, mapped objects, caught exception, or
request-scoped collaborator.

Mapping failures retain structured pair/parameter diagnostics. Do not add mapped
source objects or their values to application logs.

## Failure reasons

`MappingExecutionFailed::reason()` exposes an optional, coarse
`MappingFailureReason` identifying the failing boundary:

- `GeneratedMappingFailed` — a generated conventional mapper failed (source
  getter, transformer, target constructor, or another generated failure).
- `CustomMapperFailed` — an application custom mapper threw.
- `ProviderUnavailable` — a provider-backed custom mapping had no provider.
- `ProviderResolutionFailed` — the provider lookup threw.
- `UnexpectedTarget` — a custom mapper returned a value that is not the
  requested target.
- `CollectionElementType` — an authenticated collection element failed its type
  check.

Reasons are boundary-level only. They never distinguish a getter failure from a
constructor failure (both are `GeneratedMappingFailed`), never include source
data, provider identifiers, or original exception messages, and are not derived
from an application-thrown `MappingExecutionFailed` (a forged reason is
re-wrapped with the boundary's own reason). `reason()` is `null` for legacy
construction and for failures outside these boundaries.

A nested custom or provider custom failure can surface to the caller as the
enclosing conventional mapping's `GeneratedMappingFailed`: the child boundary's
reason is not guaranteed to survive a parent re-wrap. Treat `reason()` as the
category of the boundary that actually threw to the caller, not a full chain.

## Benchmark methodology

Run the included benchmark after dependency installation:

```sh
php tools/benchmark.php
```

It emits JSON with the median of five rounds for throughput, wall-clock time,
current-process user/system CPU time, and PHP-memory deltas. CPU time excludes
child processes, so do not use the cold-first result as a total deployment CPU
measurement: it includes generated-file linting in a child PHP process.

Use one frozen physical harness and the same fixtures for baseline and final
comparisons. `--runtime-root` selects the library source while keeping the
harness and fixtures identical; the JSON `runtime_isolation` record verifies
which source tree was loaded. Keep PHP settings, CPU affinity, iterations, and
rounds identical across runs. Repeat representative workloads on deployment
hardware with the real providers and custom mappers; the CLI numbers below are
not a production capacity guarantee.

### Historical 0.9.0 measurements

The tables and figures in this section describe **release `0.9.0`** (generated
format `7`) and are retained for history. They are **not** format-8
measurements and must not be presented as such. Re-run the harness on the
current release for new numbers.

For release `0.9.0`, a seven-round run after warmup produced the following
medians. The run used PHP 8.5.8 CLI on an Intel Core Ultra 5 135U, pinned to one
CPU; OPcache CLI was enabled, while JIT, PCOV, and Xdebug were disabled:

| Scenario | Default | Prepared | CPU time per mapping |
| --- | ---: | ---: | ---: |
| Simple DTO | 8,814 ops/s | 1,631,152 ops/s | 0.113335 ms → 0.000614 ms |
| Nested DTO | 3,220 ops/s | 346,640 ops/s | 0.312951 ms → 0.002886 ms |
| Collection of 100 DTOs | 3,029 ops/s | 38,459 ops/s | 0.330580 ms → 0.026020 ms |

Reproduce with:

```sh
XDEBUG_MODE=off taskset -c 2 php -d pcov.enabled=0 -d opcache.enable_cli=1 -d opcache.jit=disable tools/benchmark.php --workload=legacy --rounds=7
```

Execution-context workloads (flat, deep, diamond, collection, native Fiber,
expected failure) report correctness checks outside timing and repeated-call PHP
and allocator-retention deltas. Use `--execution-context-mode=prepared`,
`default`, or `both`; select a shape with `--workload=flat`, `collection`,
`deep`, `diamond`, `fiber`, or `failure`. The collection matrix
(`--workload=collections`) covers sizes 0, 1, 100, and 1000 for conventional
leaves, structural children, transformer-backed leaves, direct custom, and
provider custom mappers in both cache modes.

A focused `0.9.0` versus `0.8.0` comparison (PHP 8.5.8 CLI, pinned to CPU 2,
OPcache on, JIT/PCOV/Xdebug off) measured prepared collections of 1000 elements:

| Prepared collection, 1000 elements | 0.8.0 collections/s | 0.9.0 collections/s | 0.9.0 / 0.8.0 throughput |
| --- | ---: | ---: | ---: |
| Conventional leaf | 757–773 | 4,139–4,194 | 5.351–5.543× |
| Direct custom | 1,758–1,763 | 3,962–4,055 | 2.247–2.307× |
| Provider custom | 1,545–1,563 | 3,009–3,063 | 1.947–1.960× |

Ratios compare paired run medians; they are not confidence intervals or
application speedups. All repeated-call retention probes were zero. These CLI
measurements do not establish FPM capacity or an OPcache-off speedup.

Release `0.9.0` passed 271 tests / 3,014 assertions on PHP 8.2–8.5. Coverage
included suspended/resumed getters, transformers and custom mappers, failed
provider lookups, independent main-context work, public-cache reentry, partial
preparation failures, failure provenance, and weak-reference cleanup.

## Upgrading generated cache to format 8

The next release changes generated-mapper cache format from `7` to `8`.
Format-7 files are not reused. Deploy the application code and trusted
registrations, rotate to a fresh owner-only (`0700`) cache directory, and warm
it as the runtime owner before serving traffic. Generated files remain `0600`.
With `generateOnDemand: false`, deployment must complete warmup successfully
before requests reach the release.

Restart or reload every long-running PHP worker so it loads the new runtime and
generated mappers; this is required for workers using prepared-mapping reuse
too. Do not mix an older runtime with newly generated format-8 code.

## Historical: upgrading generated cache to format 7

Upgrading from `0.8.0` to `0.9.0` changed generated-mapper cache format from `6`
to `7`. Format-6 files are not reused. The same rotation and restart procedure
applied. This section is retained for history.
