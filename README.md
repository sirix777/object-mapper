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
    ],
    ignoredSource: ['passwordHash'],
);
```

`MapRule::from()` selects exactly a public, non-static, typed source property;
it never falls back to a getter. `MapRule::fromGetter()` selects exactly a
public, non-static, zero-argument `get*()` method with a declared return type.
The conventional `is*()` lookup remains available only for boolean target
parameters. Every public source property must be mapped or listed in
`ignoredSource`; an ignored name must still name an existing public property.

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
check as generated mappers. `warmup()` deliberately skips them and returns only
the generated conventional mapping keys, so a custom mapper is never executed
as a warmup side effect.

## Cache warmup

Use a non-public **owner-only (`0700`)** cache directory. The deployment user
must warm it, and the runtime user must be the same owner so it can read the
generated owner-only (`0600`) files. Production keeps `generateOnDemand`
disabled and explicitly warms the registered mappings before traffic reaches
the release:

```php
$mapper->warmup();
```

Warmup compiles every conventional pair and reports all failures together. It is safe to
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
- Mapping exceptions identify the pair, target parameter, or configured
  selector needed for diagnosis. They do not include mapped values; do not add
  source objects containing sensitive data to application logs.

## Non-goals in 0.1.0

This is not a serializer or a mapper for untrusted HTTP/JSON input. It has no
automatic casts, transformations, nested/collection traversal, reverse
mapping, mapping into existing objects, framework/container integration,
source-side attributes, or custom-mapper service resolution. Attributes and
transformations are deliberately deferred; use explicit profiles or
hand-written mappers at trusted boundaries.
