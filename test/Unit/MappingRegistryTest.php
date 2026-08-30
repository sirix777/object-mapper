<?php

declare(strict_types=1);

namespace Sirix\ObjectMapperTest\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sirix\ObjectMapper\Contract\CustomObjectMapperInterface;
use Sirix\ObjectMapper\Definition\CustomMappingDefinition;
use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Definition\ProviderCustomMappingDefinition;
use Sirix\ObjectMapper\Definition\SourceMatchMode;
use Sirix\ObjectMapper\Exception\MappingNotRegistered;
use Sirix\ObjectMapper\Runtime\MappingRegistry;
use Sirix\ObjectMapperTest\Support\AbstractFixture;
use Sirix\ObjectMapperTest\Support\DefaultSource;
use Sirix\ObjectMapperTest\Support\DefaultTarget;
use stdClass;

use function bin2hex;
use function class_alias;
use function class_exists;
use function iterator_to_array;
use function random_bytes;
use function sprintf;

#[CoversClass(MappingRegistry::class)]
#[CoversClass(ProviderCustomMappingDefinition::class)]
final class MappingRegistryTest extends TestCase
{
    public function testItRetrievesAndIteratesExactDefinitions(): void
    {
        $mappingDefinition = new MappingDefinition(DefaultSource::class, DefaultTarget::class);
        $mappingRegistry   = new MappingRegistry([$mappingDefinition]);

        self::assertSame($mappingDefinition, $mappingRegistry->get(DefaultSource::class, DefaultTarget::class));
        self::assertSame([
            $mappingDefinition->key() => $mappingDefinition,
        ], iterator_to_array($mappingRegistry->all()));
    }

    public function testItRejectsUnknownAndDuplicatePairs(): void
    {
        $mappingDefinition = new MappingDefinition(DefaultSource::class, DefaultTarget::class);
        $mappingRegistry   = new MappingRegistry([$mappingDefinition]);

        $this->expectException(MappingNotRegistered::class);
        $mappingRegistry->get(DefaultTarget::class, DefaultSource::class);
    }

    public function testItRejectsDuplicateAndAbstractDefinitions(): void
    {
        $mappingDefinition = new MappingDefinition(DefaultSource::class, DefaultTarget::class);

        $this->expectException(InvalidArgumentException::class);
        new MappingRegistry([$mappingDefinition, $mappingDefinition]);
    }

    public function testItRejectsDuplicatePairsAcrossDefinitionKinds(): void
    {
        $mappingDefinition        = new MappingDefinition(DefaultSource::class, DefaultTarget::class);
        $customMappingDefinition  = new CustomMappingDefinition(
            DefaultSource::class,
            DefaultTarget::class,
            new class implements CustomObjectMapperInterface {
                public function map(object $source): object
                {
                    return new DefaultTarget(1);
                }
            },
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(MappingDefinition::class);
        $this->expectExceptionMessage(CustomMappingDefinition::class);

        new MappingRegistry([$mappingDefinition, $customMappingDefinition]);
    }

    public function testItStoresExactSourceMatchingByDefaultAndRejectsModeOnlyDuplicates(): void
    {
        $exact = new MappingDefinition(DefaultSource::class, DefaultTarget::class);
        $proxy = new MappingDefinition(DefaultSource::class, DefaultTarget::class, sourceMatch: SourceMatchMode::CycleProxy);

        self::assertSame(SourceMatchMode::Exact, $exact->sourceMatch);
        self::assertSame(SourceMatchMode::CycleProxy, $proxy->sourceMatch);

        $this->expectException(InvalidArgumentException::class);
        new MappingRegistry([$exact, $proxy]);
    }

    public function testItSupportsTrailingSourceMatchingForDirectCustomDefinitions(): void
    {
        $customMappingDefinition = new CustomMappingDefinition(
            DefaultSource::class,
            DefaultTarget::class,
            new class implements CustomObjectMapperInterface {
                public function map(object $source): object
                {
                    return new DefaultTarget(1);
                }
            },
            SourceMatchMode::CycleProxy,
        );

        self::assertSame(SourceMatchMode::CycleProxy, $customMappingDefinition->sourceMatch);
    }

    public function testItRetrievesAndIteratesProviderBackedDefinitions(): void
    {
        $providerCustomMappingDefinition = new ProviderCustomMappingDefinition(
            DefaultSource::class,
            DefaultTarget::class,
            'default-mapper',
        );
        $mappingRegistry = new MappingRegistry([$providerCustomMappingDefinition]);

        self::assertSame($providerCustomMappingDefinition, $mappingRegistry->get(DefaultSource::class, DefaultTarget::class));
        self::assertSame('default-mapper', $providerCustomMappingDefinition->mapperId());
        self::assertSame([
            $providerCustomMappingDefinition->key() => $providerCustomMappingDefinition,
        ], iterator_to_array($mappingRegistry->all()));
    }

    public function testItRejectsDuplicatePairsBetweenConventionalAndProviderBackedDefinitions(): void
    {
        $mappingDefinition               = new MappingDefinition(DefaultSource::class, DefaultTarget::class);
        $providerCustomMappingDefinition = new ProviderCustomMappingDefinition(
            DefaultSource::class,
            DefaultTarget::class,
            'default-mapper',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(MappingDefinition::class);
        $this->expectExceptionMessage(ProviderCustomMappingDefinition::class);

        new MappingRegistry([$mappingDefinition, $providerCustomMappingDefinition]);
    }

    public function testItRejectsDuplicatePairsBetweenDirectAndProviderBackedDefinitions(): void
    {
        $customMappingDefinition = new CustomMappingDefinition(
            DefaultSource::class,
            DefaultTarget::class,
            new class implements CustomObjectMapperInterface {
                public function map(object $source): object
                {
                    return new DefaultTarget(1);
                }
            },
        );
        $providerCustomMappingDefinition = new ProviderCustomMappingDefinition(
            DefaultSource::class,
            DefaultTarget::class,
            'default-mapper',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(CustomMappingDefinition::class);
        $this->expectExceptionMessage(ProviderCustomMappingDefinition::class);

        new MappingRegistry([$customMappingDefinition, $providerCustomMappingDefinition]);
    }

    public function testItRejectsAbstractClassesInDefinitions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MappingDefinition(AbstractFixture::class, DefaultTarget::class);
    }

    public function testItRejectsAliasesForEveryBuiltInDefinitionRole(): void
    {
        $sourceAlias = 'SirixObjectMapperTestDefinitionAliasSource' . bin2hex(random_bytes(4));
        $targetAlias = 'SirixObjectMapperTestDefinitionAliasTarget' . bin2hex(random_bytes(4));
        $this->registerClassAlias(DefaultSource::class, $sourceAlias);
        $this->registerClassAlias(DefaultTarget::class, $targetAlias);

        $definitions = [
            MappingDefinition::class               => [
                'source' => static fn (): MappingDefinition => new MappingDefinition($sourceAlias, DefaultTarget::class),
                'target' => static fn (): MappingDefinition => new MappingDefinition(DefaultSource::class, $targetAlias),
            ],
            CustomMappingDefinition::class         => [
                'source' => static fn (): CustomMappingDefinition => new CustomMappingDefinition($sourceAlias, DefaultTarget::class, new class implements CustomObjectMapperInterface {
                    public function map(object $source): object
                    {
                        return new DefaultTarget(1);
                    }
                }),
                'target' => static fn (): CustomMappingDefinition => new CustomMappingDefinition(DefaultSource::class, $targetAlias, new class implements CustomObjectMapperInterface {
                    public function map(object $source): object
                    {
                        return new DefaultTarget(1);
                    }
                }),
            ],
            ProviderCustomMappingDefinition::class => [
                'source' => static fn (): ProviderCustomMappingDefinition => new ProviderCustomMappingDefinition($sourceAlias, DefaultTarget::class, 'test-mapper'),
                'target' => static fn (): ProviderCustomMappingDefinition => new ProviderCustomMappingDefinition(DefaultSource::class, $targetAlias, 'test-mapper'),
            ],
        ];

        foreach ($definitions as $definitionClass => $definitionRoles) {
            foreach ($definitionRoles as $role => $createDefinition) {
                try {
                    $createDefinition();
                    self::fail(sprintf('Expected %s to reject an alias %s.', $definitionClass, $role));
                } catch (InvalidArgumentException $exception) {
                    self::assertSame(
                        sprintf('Mapping %s class "%s" must use its canonical class name "%s".', $role, 'source' === $role ? $sourceAlias : $targetAlias, 'source' === $role ? DefaultSource::class : DefaultTarget::class),
                        $exception->getMessage(),
                        $definitionClass . ' ' . $role,
                    );
                }
            }
        }
    }

    public function testItRejectsAnonymousConventionalDefinitionsAndKeepsCustomDefinitionsUsable(): void
    {
        $anonymousSource = new class {};
        $anonymousTarget = new class {};

        foreach ([
            'source' => [$anonymousSource::class, DefaultTarget::class],
            'target' => [DefaultSource::class, $anonymousTarget::class],
        ] as $role => [$source, $target]) {
            try {
                new MappingDefinition($source, $target);
                self::fail(sprintf('Expected anonymous %s class to be rejected.', $role));
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('Mapping ' . $role . ' class "', $exception->getMessage());
                self::assertStringContainsString('must be a named concrete class.', $exception->getMessage());
            }
        }

        $customMappingDefinition = new CustomMappingDefinition(
            $anonymousSource::class,
            $anonymousTarget::class,
            new class implements CustomObjectMapperInterface {
                public function map(object $source): object
                {
                    return $source;
                }
            },
        );
        $providerCustomMappingDefinition = new ProviderCustomMappingDefinition(
            $anonymousSource::class,
            $anonymousTarget::class,
            'test-mapper',
        );

        self::assertSame($anonymousSource::class, $customMappingDefinition->source());
        self::assertSame($anonymousTarget::class, $customMappingDefinition->target());
        self::assertSame($anonymousSource::class, $providerCustomMappingDefinition->source());
        self::assertSame($anonymousTarget::class, $providerCustomMappingDefinition->target());
    }

    public function testItRejectsAliasesBeforeApplyingTheConventionalNamedClassRequirement(): void
    {
        $anonymousSource = new class {};
        $sourceAlias     = 'SirixObjectMapperTestDefinitionAliasAnonymousSource' . bin2hex(random_bytes(4));
        $this->registerClassAlias($anonymousSource::class, $sourceAlias);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'Mapping source class "%s" must use its canonical class name "%s".',
            $sourceAlias,
            $anonymousSource::class,
        ));

        new MappingDefinition($sourceAlias, DefaultTarget::class);
    }

    public function testItRejectsInvalidProviderBackedDefinitionSource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Mapping source class "' . AbstractFixture::class . '" must be concrete.');

        new ProviderCustomMappingDefinition(AbstractFixture::class, DefaultTarget::class, 'default-mapper');
    }

    public function testItRejectsInvalidProviderBackedDefinitionTarget(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Mapping target class "' . AbstractFixture::class . '" must be concrete.');

        new ProviderCustomMappingDefinition(DefaultSource::class, AbstractFixture::class, 'default-mapper');
    }

    public function testItRejectsEmptyProviderBackedDefinitionMapperId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Custom mapper identifier must not be empty.');

        new ProviderCustomMappingDefinition(DefaultSource::class, DefaultTarget::class, " \t\n");
    }

    public function testItRejectsNonStringIgnoredSourceEntries(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('valid string identifier');

        new MappingDefinition(DefaultSource::class, DefaultTarget::class, ignoredSource: $this->invalidIgnoredSource());
    }

    private function invalidIgnoredSource(): mixed
    {
        return [new stdClass()];
    }

    /**
     * @param class-string $class
     *
     * @phpstan-assert class-string $alias
     */
    private function registerClassAlias(string $class, string $alias): void
    {
        class_alias($class, $alias);

        if (! class_exists($alias)) {
            self::fail('Could not register definition test class alias.');
        }
    }
}
