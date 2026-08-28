# Changelog

All notable changes to this project are documented in this file. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
