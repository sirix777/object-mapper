# Changelog

All notable changes to this project are documented in this file. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `MappingExecutionFailed::reason(): ?MappingFailureReason` exposes a coarse,
  boundary-level failure category (`GeneratedMappingFailed`,
  `CustomMapperFailed`, `ProviderUnavailable`, `ProviderResolutionFailed`,
  `UnexpectedTarget`, `CollectionElementType`). Reasons never include source
  data, provider identifiers, or original exception messages, and a forged
  reason on an application-thrown `MappingExecutionFailed` is not trusted.

### Changed

- Conventional mappings now reject by-reference target constructor parameters
  during metadata compilation, before resolving that parameter's source member
  or transformer. Previously such parameters were accepted and generated code
  could mutate the source property. Transformer by-reference validation is
  unchanged.
- On PHP 8.4+, conventional discovery and explicit property selectors now
  require a readable source property. Write-only virtual hooked properties are
  rejected during metadata compilation; virtual get/set hooks and backed
  properties with a set hook stay readable, and convention can still fall back
  to a getter. PHP 8.2/8.3 behavior is unchanged.
- Conventional mappers now evaluate constructor arguments in constructor order:
  each source read, transformer, nested and collection operation completes
  before the next parameter is processed. Nullable members are no longer read
  ahead of earlier parameters, so callback and failure ordering follows the
  target constructor parameter order.
- With a fixed built-in `MappingRegistry` and `reusePreparedMappings: true`,
  compiled structural scope tables are now reused between roots instead of being
  rebuilt. Custom or wrapped `MappingRegistryInterface` registries keep
  validating compiled bindings on every prepared root, so a swapped child
  definition or source-match mode is still rejected. Only inert binding tables
  are retained; no execution frame, source, or provenance is reused.
- Generated-mapper cache format changed to `8`. Format-7 files are not reused.
  Deploy code and registrations, rotate the old owner-only cache, warm the new
  `0700` cache as its runtime owner before traffic, and restart or reload all
  long-running workers, including prepared-mapping reuse. Generated files remain
  `0600`.
- Production, trust, lifecycle, invalidation, platform limits, and benchmark
  methodology are now consolidated in `docs/production.md`; the README keeps a
  concise quickstart and links to it. Historical `0.9.0` measurements are marked
  historical and are not format-8 results.
- Integration tests are split by responsibility (Cycle proxy, structural
  mapping, custom mapping, warmup, prepared cache, execution isolation). The
  shared fixtures and base test case moved to `test/Support`; the test inventory
  is unchanged (no case removed or duplicated).

## [0.9.0] - 2026-09-09

### Changed

- Mapping runtime state now uses internal execution contexts and restorable
  frames instead of Fiber scope arrays. Main-context mappings, multiple native
  Fibers, recursive public cache entries, and suspended/resumed callbacks keep
  independent declared-dependency boundaries and failure provenance. Cleanup
  also covers partial preparation and callback failures; completed frames retain
  no mapped objects, caught failures, or request-scoped collaborators. Public
  APIs and prepared-cache lifecycle are unchanged.
- Conventional root and nested leaves avoid structural execution tables, using
  eligibility recorded during preparation without rescanning warm prepared calls.
  This change preserves public APIs, prepared-cache ownership/lifecycle,
  and default source-file invalidation.
- Conventional leaf collections, including transformer-backed leaves, bind the
  declared child dependency once per collection in a runtime-owned loop. Child
  execution remains isolated per element, with ordered validation and callbacks,
  unchanged null/list semantics, exact/Cycle matching, and sanitized input keys.
  Structural conventional children retain generated per-element dispatch.
- Direct/provider-custom collections now also bind their validated dependency
  once per collection, eliminating repeated nested dispatch and source checks.
  Per-item source/target validation, provider resolution, callback isolation,
  ordered failures, and context restoration remain intact. Format-7 generated
  collection helpers use the new runtime path.
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

- Regression coverage for wrong-target short-circuiting, recovery, suspended
  or failed provider lookups, native Fiber interleaving, public-cache reentry,
  partial preparation failures, diagnostic provenance, and weak-reference
  cleanup. The complete suite passed 271 tests / 3,014 assertions on PHP
  8.2.32, 8.3.32, 8.4.23, and 8.5.8.
- Reproducible flat, getter/transformer, nested, deep, diamond, collection,
  native-Fiber, and expected-failure benchmarks with correctness and retention
  probes. The `--runtime-root` option verifies the selected runtime source
  while keeping one physical harness for comparisons between `0.8.0` and
  `0.9.0`.
- Focused prepared collections of 1000 elements improved 5.351–5.543× for
  conventional leaves, 2.247–2.307× for direct custom, and 1.947–1.960× for
  provider custom in `0.9.0` versus `0.8.0`, retaining callback isolation.
  Measurements used PHP 8.5.8 CLI with OPcache on and JIT/coverage off.
  Repeated-call retention probes were zero; the full 40-workload collection
  matrix also passed correctness and retention checks. See the
  [comparison protocol and limits](README.md#benchmarking-collection-execution).

### Security

- Root, nested, and collection leaf getters, transformers, and constructors run
  behind an isolation barrier. Replayed parent diagnostics are sanitized even
  with unconsumed provenance; eight regression cases fail on `0.8.0`
  and pass on `0.9.0`. Enclosing scopes restore on success or failure.
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
