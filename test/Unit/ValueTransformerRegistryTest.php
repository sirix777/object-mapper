<?php

declare(strict_types=1);

namespace Sirix\ObjectMapperTest\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sirix\ObjectMapper\Exception\ValueTransformerNotRegistered;
use Sirix\ObjectMapper\Runtime\ValueTransformerRegistry;
use Sirix\ObjectMapperTest\Support\UuidToStringTransformer;
use Sirix\ObjectMapperTest\Support\UuidToStringTransformerChild;
use stdClass;

#[CoversClass(ValueTransformerRegistry::class)]
final class ValueTransformerRegistryTest extends TestCase
{
    public function testItRetrievesOnlyTheExplicitlyRegisteredExactClass(): void
    {
        $uuidToStringTransformer     = new UuidToStringTransformer();
        $valueTransformerRegistry    = new ValueTransformerRegistry([$uuidToStringTransformer]);

        self::assertSame($uuidToStringTransformer, $valueTransformerRegistry->get(UuidToStringTransformer::class));

        $this->expectException(ValueTransformerNotRegistered::class);
        $valueTransformerRegistry->get(UuidToStringTransformerChild::class);
    }

    public function testItRejectsAnUnknownTransformer(): void
    {
        $valueTransformerRegistry = new ValueTransformerRegistry([]);

        $this->expectException(ValueTransformerNotRegistered::class);
        $this->expectExceptionMessage(UuidToStringTransformer::class);
        $valueTransformerRegistry->get(UuidToStringTransformer::class);
    }

    public function testItRejectsDuplicateTransformerClasses(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(UuidToStringTransformer::class);

        new ValueTransformerRegistry([new UuidToStringTransformer(), new UuidToStringTransformer()]);
    }

    public function testItRejectsValuesOutsideTheMarkerContract(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(stdClass::class);

        new ValueTransformerRegistry([new stdClass()]);
    }
}
