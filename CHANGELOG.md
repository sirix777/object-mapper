# Changelog

All notable changes to this project are documented in this file. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- Mapping runtime state now uses internal execution contexts and restorable
  frames instead of Fiber scope arrays. Main-context mappings, multiple native
  Fibers, recursive public cache entries, and suspended/resumed callbacks keep
  independent declared-dependency boundaries and failure provenance. Cleanup
  also covers partial preparation and callback failures; completed frames retain
  no mapped objects, caught failures, or request-scoped collaborators. Public
  APIs, generated format `7`, and prepared-cache lifecycle are unchanged.
- Conventional root and nested leaves avoid structural execution tables, using
  eligibility recorded during preparation without rescanning warm prepared calls.
  This change preserves public APIs, generated format `7`, prepared-cache
  ownership/lifecycle, and default source-file invalidation.
- Conventional leaf collections, including transformer-backed leaves, bind the
  declared child dependency once per collection in a runtime-owned loop. Child
  execution remains isolated per element, with ordered validation and callbacks,
  unchanged null/list semantics, exact/Cycle matching, and sanitized input keys.
  Structural and direct/provider-custom children retain generated per-element
  dispatch; providers still resolve once per attempted invocation.
- Generated code optionally uses the internal `CollectionMappingRuntimeInterface`;
  existing `NestedMappingRuntimeInterface` implementations and the public
  `ObjectMapper::map()` entry remain compatible. No reusable binding handle or
  unchecked public mapper entry is exposed.
- Generated-mapper cache format changed to `7`. Deploy code and registrations,
  rotate the old owner-only cache, and warm the new `0700` cache as its runtime
  owner before traffic. Format-6 files are not reused; generated files remain
  `0600`. Restart or reload all long-running workers, including those using
  prepared-mapping reuse.

### Added

- Leaf benchmarks against `81845e5` with a frozen shared harness: prepared flat
  mappings improved 1.97–2.47×, getter/transformer leaves 1.88–2.67×, and nested
  leaves with OPcache 1.19–1.33×. Empty prepared custom collections without
  OPcache measured 0.953×/0.958× (+0.153/+0.131 µs per call); attribution remains
  unconfirmed. See [environment, protocol, and limits](README.md#benchmarking-lightweight-leaf-execution).
- Regression verification passed on PHP 8.2.32, 8.3.32, 8.4.23, and 8.5.8
  (257 tests / 2712 assertions each), with final `composer check` passing.
- Execution-context regressions passed: 24 focused tests / 558 assertions and
  181 integration tests / 2,543 assertions cover interleaved native Fibers,
  resumed nested getter/transformer/custom-mapper calls, public-cache reentry,
  failure provenance, partial preparation failures, and weak-reference cleanup.
  The final complete suite passed 267 tests / 2,876 assertions.
  The benchmark harness now provides reproducible flat, deep, diamond,
  collection, native-Fiber, and expected-failure context workloads with
  correctness and repeated-call retention probes. An identical-harness A/B/A/B
  comparison against baseline `d0ca7c4`, using one harness and seven stable
  prepared rounds on PHP 8.5.8 CLI (CPU 2; OPcache on; JIT/PCOV off;
  `XDEBUG_MODE=off`), together with additional focused and balanced controls,
  found repeatable deep/diamond/collection gains; the full A/B/A/B diamond pair
  was mixed. The fail-closed `--runtime-root` check recorded
  `runtime_isolation=verified` with 15 class provenance records in every final
  JSON. All success and failure repeated-retention and allocator deltas were
  zero; OPcache-off controls found no repeatable unexplained regression beyond
  same-version variation. Fiber was order/frequency-sensitive and is
  inconclusive; exception-dominated failure results are not claimed as gains.
  The performance gate passes. See [the
  protocol, ratios, and limits](README.md#benchmarking-execution-contexts).
- Collection benchmarks for sizes 0, 1, 100, and 1000 across leaf, structural,
  transformer, direct-custom, and provider-custom children, with default and
  prepared-cache execution, raw timings, items/s and retention probes. The
  comparison baseline is revision `38f002c` using the identical harness. These
  measurements predate lightweight leaf execution and the execution-context
  runtime work; they are not incremental results for either later change.
- On PHP 8.5.8 CLI with OPcache on and JIT/coverage off, prepared collections
  of 1000 conventional leaves improved 4.46–5.27× in paired run medians versus
  `38f002c`. Prepared custom/provider throughput was 18–39% lower alongside
  the required callback isolation fix; noisy controls prevent attributing the
  whole difference to isolation. See the [measurement protocol and results](README.md#benchmarking-collection-execution).

### Security

- Root, nested, and collection leaf getters, transformers, and constructors run
  behind an isolation barrier. Replayed parent diagnostics are sanitized even
  with unconsumed provenance; eight new regression cases fail on the baseline
  and pass on the candidate. Enclosing scopes restore on success or failure.
- Custom mapper callbacks and provider resolution no longer inherit an enclosing
  mapping's declared dependency authority. This prevents dispatch of enclosing-only
  siblings and forged collection errors for nested/collection custom mappings
  and independent custom roots invoked from a callback. Enclosing scope is
  restored on success or failure; public root mapping and its separately wired
  provider remain available.

## [0.8.0] - 2026-08-31

### Added

- Opt-in `MapperCache` prepared-mapping reuse for conventional mappings. It
  caches validated metadata, generated-cache identity, and the generated mapper
  by `MappingDefinition` object identity for the lifetime of a PHP worker.

### Changed

- Production applications may enable `reusePreparedMappings` with
  `generateOnDemand: false` after worker-boot `warmup()`. This mode is intended
  for a fixed application-owned registry; workers must be restarted or reloaded
  after changes to mapped classes, transformers, or mapping registrations.
- FrankenPHP and RoadRunner workers warm before accepting traffic and reload
  after deployment. Swoole/OpenSwoole support is limited to non-yielding
  mapping operations; native PHP Fiber coverage does not establish a
  coroutine-local Swoole/OpenSwoole context.

### Security

- Prepared entries are process-local and must not be populated from request,
  tenant, or user-controlled mapping definitions. Mapping diagnostics remain
  structured and must not be supplemented by logging mapped objects or values.

## [0.7.0] - 2026-08-30

### Added

- `WarmableObjectMapperInterface`, an additive lifecycle contract for
  deployment-time mapper cache warmup. `ObjectMapperInterface` remains
  mapping-only for compatibility with application-owned implementations.

### Changed

- Built-in definitions now reject class aliases and require canonical class
  names. Conventional mappings additionally require named concrete classes;
  direct and provider-backed custom mappings retain anonymous concrete-class
  support.
- `MappingDefinitionInterface` is documented as a read/registry contract, not
  an executable extension SPI. Custom execution remains available through a
  direct `CustomObjectMapperInterface` mapper or a closed opaque provider ID.
- Generated-mapper cache format remains `6`; this release does not change
  generated source, cache identity, cache permissions, or cache loading.

## [0.6.0] - 2026-08-30

### Added

- `SourceMatchMode::CycleProxy` opt-in for direct `MappingDefinition` and
  `CustomMappingDefinition` registrations. Direct entities keep mapping, while
  only a direct Cycle `EntityProxyInterface` child of the registered source is
  additionally accepted.

### Changed

- Generated-mapper cache format changed to `6`. Deploy `0.6.0` code and
  registrations, clear or rotate the former owner-only cache, then warm the
  new `0700` cache as the runtime owner. Format-5 files are not reused and
  generated files remain `0600`.

### Security

- This is not generic polymorphic mapping. Provider-backed definitions remain
  exact-only, Cycle is detected without a package dependency, and mapping does
  not modify relation loading or application N+1-query responsibilities.

## [0.5.0] - 2026-08-30

### Added

- Closed `MapRule::constant()` registration for trusted `null`, `bool`, `int`,
  finite `float`, and `string` target constructor values. Values are checked
  against the declared target type during warmup without scalar coercion.
- Type-tagged constant metadata and deterministic generated PHP literals.
  Constant changes participate in root and conventional nested dependency
  cache identity.

### Changed

- Generated-mapper cache format changed to `5`. Deploy code and registrations,
  clear or rotate the stale owner-only cache directory, then warm the new cache
  as its owner; format-4 files are not reused.
- Constants do not select or ignore source members. Existing public-source
  completeness checks remain in force.

### Security

- Generated code exports only validated fixed scalar/null literals. It does not
  evaluate expressions, invoke callbacks, read source data for constants, or
  perform service/container lookup.
- No conditional mapping API shipped. Authorization, redaction, token handling,
  decryption, I/O, and other policy remain explicit typed transformers or
  application-owned custom mappers. Do not register secrets or request data as
  constants because generated owner-only cache files contain those literals.

## [0.4.0] - 2026-08-29

### Added

- `CustomObjectMapperProviderInterface` and
  `ProviderCustomMappingDefinition` for application-owned, opaque custom mapper
  identifiers resolved only at mapping runtime.
- Optional provider wiring for root, nested, and collection custom mappings.
  Provider-backed identifiers participate in structural cache identity, but no
  resolved mapper or service is stored in generated metadata or cache files.

### Changed

- Direct `CustomMappingDefinition` wiring and constructors remain unchanged.
  Applications using provider-backed definitions pass the same optional provider
  to `ObjectMapper` and `MapperCache`.
- The core remains framework- and container-free, with no production dependency
  additions. Any framework/PSR-11 bridge is a separately released package with
  its own compatible core range.

### Security

- Provider lookup and custom mapper failures are normalized to pair-only
  execution errors. Warmup does not resolve, construct, or execute custom
  mappers, including provider-backed structural children.

## [0.3.0] - 2026-08-29

### Added

- Explicit `MapRule::nested()` and `MapRule::collection()` operations for
  exact-pair nested DTO and `array` collection mapping.
- Deterministic nested conventional-mapping warmup with missing-pair and cycle
  diagnostics; custom children are validated but not executed during warmup.
- Generated collection mapping that preserves order, emits list output, and
  reports a safe class/key diagnostic for an invalid runtime element. Integer
  keys are shown directly; string keys use a stable SHA-256 prefix and length.

### Changed

- `MappingMetadataFactory` and `MapperCache` now receive the shared mapping
  registry when structural rules are used. Pass the same registry to both
  construction sites.
- Generated mapper/cache format changed to `4`. Re-warm the cache after
  upgrading; format-3 generated mapper files are not reused.

### Security

- Nested dispatch resolves only exact registered source-target pairs. Generated
  code uses fixed validated class constants and does not inspect collection
  values beyond their runtime type.

## [0.2.0] - 2026-08-28

### Added

- `MapRule::fromMethod()` for deliberate selection of a public, non-static,
  typed, zero-argument source method without expanding conventional discovery.
- `MapRule::through()` and the explicit value-transformer contracts and
  registry for type-checked source-to-target transformations.
- Generated-mapper cache metadata now includes transformer identity, signature,
  and source-file state so changed transformers are recompiled on warmup.

### Changed

- `MappingMetadataFactory` and `MapperCache` construction now require the same
  explicitly assembled transformer registry. Update application wiring, test
  helpers, and cache-warmup commands accordingly.
- Deploy transformer code and registry wiring before running warmup; re-warm
  the owner-only mapper cache after transformer signature or file changes.

### Security

- Transformers are registered by exact class only. The mapper does not perform
  implicit casts, instantiate transformer classes, or use service/container
  lookup. Transformer methods and both type-compatibility edges are validated
  before generated code is loaded.

## [0.1.0] - 2026-08-28

### Added

- External conventional mapping profiles with explicit property/getter rename
  rules and checked ignored public source fields.
- `CustomMappingDefinition` and `CustomObjectMapperInterface` for
  application-owned hand-written mappers with exact-pair and target-type
  guarantees.
- Profile-aware generated-mapper cache identity and custom-mapper-safe warmup.
- Explicit mapping definitions and a registry for exact concrete class pairs.
- Strict reflection metadata validation for public properties and safe getters.
- Type compatibility checks covering nullability, unions, intersections,
  `mixed`, inheritance, and reflection-relative class types.
- Deterministic generated mappers, locked atomic cache writes, linting, and
  explicit warmup.
- Unit and integration PHPUnit coverage, package bootstrap, and `test/`
  suite configuration.

### Security

- Generated code is limited to validated registered pairs and public source
  reads; runtime target selection cannot bypass the registry.
- Mapper cache directories and generated files require owner-only permissions;
  symbolic links are rejected and cache files are loaded while locked.
