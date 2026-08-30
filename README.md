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
use Sirix\ObjectMapper\Runtime\ValueTransformerRegistry;

$registry = new MappingRegistry([
    new MappingDefinition(UserResult::class, UserDto::class),
]);

$transformers = new ValueTransformerRegistry([
    new UuidToString(),
    new DateTimeToAtom(),
]);

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

Generated-mapper cache format `6` includes this choice. When upgrading to
`0.6.0`, deploy code and registrations, clear or rotate the old owner-only
cache, create/use an owner-only (`0700`) cache directory, and warm it as the
runtime owner. Generated files remain owner-only (`0600`).

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
$mapper->warmup();
```

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

## Upgrading to 0.5.0

Create one `MappingRegistry` during application wiring and pass that same
instance to both `MappingMetadataFactory` and `MapperCache` for structural
rules. Existing transformer wiring remains shared in the same way. Direct
`CustomMappingDefinition` registrations are unchanged. For provider-backed
definitions, additionally pass the same optional provider to `ObjectMapper`
and `MapperCache`, as shown above. `warmup()` skips every custom mapping,
including provider-backed children: it neither resolves a provider nor creates
or executes a custom mapper.

`MapRule::constant()` is the only new mapping rule in 0.5.0; no conditional
operation shipped. The generated-mapper cache format is now `5`, so format-4
files are not reusable. Deploy the 0.5.0 code and trusted registrations first,
then clear or rotate the old owner-only cache directory, create the new
owner-only (`0700`) directory, and warm it as the runtime owner before serving
traffic.

## Mapping rules and guarantees

- Source and target must be existing concrete classes and each pair is unique.
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

## Non-goals in 0.5.0

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
