<?php

declare(strict_types=1);

namespace Cycle\ORM {
    interface EntityProxyInterface {}
}

namespace Sirix\ObjectMapperTest\Support {
    use Cycle\ORM\EntityProxyInterface;
    use Sirix\ObjectMapper\Contract\ValueTransformerInterface;

    class CycleProxyEntity
    {
        public function __construct(public int $id) {}
    }

    final class CycleProxyEntityDto
    {
        public function __construct(public int $id) {}
    }

    final class RecordingCycleProxyTransformer implements ValueTransformerInterface
    {
        public static int $invocations = 0;

        public function transform(int $value): int
        {
            ++self::$invocations;

            return $value;
        }
    }

    final class DirectCycleProxy extends CycleProxyEntity implements EntityProxyInterface {}

    final class NormalCycleEntitySubclass extends CycleProxyEntity {}

    class CycleProxyIntermediate extends CycleProxyEntity implements EntityProxyInterface {}

    final class IndirectCycleProxy extends CycleProxyIntermediate {}

    class DifferentCycleParent
    {
        public function __construct(public int $id) {}
    }

    final class WrongParentCycleProxy extends DifferentCycleParent implements EntityProxyInterface {}

    final class CycleProxyHolder
    {
        public function __construct(public CycleProxyEntity $child) {}
    }

    final class CycleProxyHolderDto
    {
        public function __construct(public CycleProxyEntityDto $child) {}
    }

    final class NullableCycleProxyHolder
    {
        public function __construct(public ?CycleProxyEntity $child) {}
    }

    final class NullableCycleProxyHolderDto
    {
        public function __construct(public ?CycleProxyEntityDto $child) {}
    }

    final class CycleProxyCollectionHolder
    {
        /** @param array<int|string, CycleProxyEntity> $children */
        public function __construct(public array $children) {}
    }

    final class CycleProxyCollectionHolderDto
    {
        /** @param list<CycleProxyEntityDto> $children */
        public function __construct(public array $children) {}
    }

    class CycleProxyRootWithChild
    {
        public function __construct(public CycleProxyEntity $child) {}
    }

    final class DirectCycleProxyRootWithChild extends CycleProxyRootWithChild implements EntityProxyInterface {}

    final class CycleProxyRootWithChildDto
    {
        public function __construct(public CycleProxyEntityDto $child) {}
    }
}
