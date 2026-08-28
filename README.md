# sirix/object-mapper

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
use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Generator\MapperCache;
use Sirix\ObjectMapper\Generator\PhpMapperGenerator;
use Sirix\ObjectMapper\Metadata\MappingMetadataFactory;
use Sirix\ObjectMapper\Runtime\MappingRegistry;
use Sirix\ObjectMapper\Runtime\ObjectMapper;

$registry = new MappingRegistry([
    new MappingDefinition(UserResult::class, UserDto::class),
]);

$mapper = new ObjectMapper(
    $registry,
    new MapperCache(
        new MappingMetadataFactory(),
        new PhpMapperGenerator(),
        __DIR__ . '/var/cache/object-mapper',
        generateOnDemand: false,
    ),
);

/** @var UserDto $dto */
$dto = $mapper->map($result, UserDto::class);
```

Register a pair once in application wiring. Mapping always uses the exact
runtime source class and requested target class; an unregistered pair raises
`MappingNotRegistered`.

## Cache warmup

Use a non-public **owner-only (`0700`)** cache directory. The deployment user
must warm it, and the runtime user must be the same owner so it can read the
generated owner-only (`0600`) files. Production keeps `generateOnDemand`
disabled and explicitly warms the registered mappings before traffic reaches
the release:

```php
$mapper->warmup();
```

Warmup compiles every pair and reports all failures together. It is safe to
run repeatedly. Development may set `generateOnDemand: true`; this is a local
convenience, not a substitute for CI/deployment warmup. Generated files are
locked, linted, atomically published, checked for safe owner-only permissions,
and ignored by Git. Do not place the cache in a shared or attacker-writable
directory.

## Mapping rules and guarantees

- Source and target must be existing concrete classes and each pair is unique.
- The target needs a public constructor. Values are passed by named argument.
- A target parameter resolves, in order, from a public non-static property,
  public zero-argument `getX()`, or boolean-only `isX()` method.
- Required source and target declarations must be type-compatible. Untyped
  source values, narrowing `mixed`, nullability violations, and unsupported
  access fail before generated code is loaded.
- Generated mappers read only the members validated at warmup; they never use
  reflection writes, magic access, or `eval()`.

## Non-goals in 0.1.0

This is not a serializer or a mapper for untrusted HTTP/JSON input. It has no
automatic renames, casts, nested/collection traversal, reverse mapping,
mapping into existing objects, framework/container integration, or custom
mapper services. Keep those exceptional policies in hand-written boundary
mappers.
