# Changelog

All notable changes to this project are documented in this file. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
