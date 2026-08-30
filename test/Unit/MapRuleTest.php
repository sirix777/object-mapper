<?php

declare(strict_types=1);

namespace Sirix\ObjectMapperTest\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sirix\ObjectMapper\Contract\CustomObjectMapperInterface;
use Sirix\ObjectMapper\Definition\MapRule;
use Sirix\ObjectMapperTest\Support\AbstractFixture;
use Sirix\ObjectMapperTest\Support\ApiAccessTokenDto;
use Sirix\ObjectMapperTest\Support\Release;
use Sirix\ObjectMapperTest\Support\ReleaseDto;
use Sirix\ObjectMapperTest\Support\UuidToStringTransformer;

use stdClass;

use function class_alias;
use function fclose;
use function fopen;

#[CoversClass(MapRule::class)]
final class MapRuleTest extends TestCase
{
    public function testItDescribesConstantRules(): void
    {
        foreach ([null, true, 42, 1.5, 'external'] as $value) {
            $rule = MapRule::constant($value);

            self::assertTrue($rule->isConstant());
            self::assertSame($value, $rule->constantValue());
            self::assertFalse($rule->selectsProperty());
            self::assertFalse($rule->selectsGetter());
            self::assertFalse($rule->selectsMethod());
            self::assertSame('constant', $rule->operation());
        }
    }

    public function testItRejectsInvalidConstantValuesWithoutDisclosingThem(): void
    {
        $resource = fopen('php://memory', 'r');
        if (false === $resource) {
            self::fail('Could not open the harmless test stream.');
        }

        try {
            foreach ([[],
                new stdClass(),
                static fn (): string => 'not allowed',
                $resource,
                NAN,
                INF,
                -INF] as $value) {
                try {
                    MapRule::constant($value);
                    self::fail('Expected an invalid constant value to be rejected.');
                } catch (InvalidArgumentException $exception) {
                    self::assertSame('Mapping rule constants must be null, bool, int, finite float, or string.', $exception->getMessage());
                }
            }
        } finally {
            fclose($resource);
        }
    }

    public function testConstantAndSelectorAccessorsAreMutuallyExclusive(): void
    {
        try {
            MapRule::constant('external')->selector();
            self::fail('Expected a constant rule selector lookup to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('A constant mapping rule does not select a source member.', $exception->getMessage());
        }

        try {
            MapRule::from('source')->constantValue();
            self::fail('Expected a selector rule constant lookup to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Only constant mapping rules have a constant value.', $exception->getMessage());
        }
    }

    public function testItRejectsConstantTerminalOperationCombinations(): void
    {
        foreach ([
            static fn () => MapRule::constant('external')->through(UuidToStringTransformer::class),
            static fn () => MapRule::constant('external')->nested(ApiAccessTokenDto::class),
            static fn () => MapRule::constant('external')->collection(Release::class, ReleaseDto::class),
        ] as $configure) {
            try {
                $configure();
                self::fail('Expected an incompatible constant terminal operation to be rejected.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('terminal operation', $exception->getMessage());
            }
        }
    }

    public function testItDescribesNestedAndCollectionOperations(): void
    {
        $nested     = MapRule::from('token')->nested(ApiAccessTokenDto::class);
        $collection = MapRule::fromGetter('getReleases')->collection(Release::class, ReleaseDto::class);

        self::assertTrue($nested->isNested());
        self::assertSame(ApiAccessTokenDto::class, $nested->nestedTarget());
        self::assertFalse($nested->hasTransformer());
        self::assertTrue($collection->isCollection());
        self::assertSame(Release::class, $collection->collectionElementSource());
        self::assertSame(ReleaseDto::class, $collection->collectionElementTarget());
    }

    public function testItRejectsCompositionOfTerminalOperations(): void
    {
        foreach ([
            static fn () => MapRule::from('token')->through(UuidToStringTransformer::class)->nested(ApiAccessTokenDto::class),
            static fn () => MapRule::from('token')->nested(ApiAccessTokenDto::class)->collection(Release::class, ReleaseDto::class),
            static fn () => MapRule::from('token')->collection(Release::class, ReleaseDto::class)->through(UuidToStringTransformer::class),
        ] as $configure) {
            try {
                $configure();
                self::fail('Expected incompatible terminal operations to be rejected.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('terminal operation', $exception->getMessage());
            }
        }
    }

    public function testItRejectsInvalidStructuralClassStrings(): void
    {
        $anonymous = new class {};

        foreach ([
            static fn () => MapRule::from('token')->nested('missing\ClassName'),
            static fn () => MapRule::from('token')->nested(AbstractFixture::class),
            static fn () => MapRule::from('token')->nested(CustomObjectMapperInterface::class),
            static fn () => MapRule::from('token')->nested($anonymous::class),
            static fn () => MapRule::from('releases')->collection(AbstractFixture::class, ReleaseDto::class),
        ] as $configure) {
            try {
                $configure();
                self::fail('Expected an invalid structural class to be rejected.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testItRejectsNonCanonicalAndLexicallyUnsafeClassAliases(): void
    {
        $alias = 'Unsafe;NestedRuleAlias';
        class_alias(ApiAccessTokenDto::class, $alias);

        try {
            MapRule::from('token')->nested($alias);
            self::fail('Expected a lexically unsafe class alias to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('valid canonical class name', $exception->getMessage());
        }

        $canonical         = ApiAccessTokenDto::class;
        $nonCanonicalAlias = $canonical . 'Alias';
        class_alias($canonical, $nonCanonicalAlias);

        try {
            MapRule::from('token')->nested($nonCanonicalAlias);
            self::fail('Expected a non-canonical class alias to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('canonical class name', $exception->getMessage());
        }
    }
}
