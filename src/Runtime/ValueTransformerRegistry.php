<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Runtime;

use InvalidArgumentException;
use LogicException;
use Sirix\ObjectMapper\Contract\ValueTransformerInterface;
use Sirix\ObjectMapper\Contract\ValueTransformerRegistryInterface;
use Sirix\ObjectMapper\Exception\ValueTransformerNotRegistered;

use function get_debug_type;
use function sprintf;

final class ValueTransformerRegistry implements ValueTransformerRegistryInterface
{
    /**
     * @var array<class-string<ValueTransformerInterface>, ValueTransformerInterface>
     */
    private array $transformers = [];

    /**
     * @param iterable<mixed> $transformers
     */
    /**
     * @param iterable<ValueTransformerInterface> $transformers
     *
     * @phpstan-param iterable<mixed> $transformers
     */
    public function __construct(iterable $transformers = [])
    {
        foreach ($transformers as $transformer) {
            if (! $transformer instanceof ValueTransformerInterface) {
                throw new InvalidArgumentException(sprintf(
                    'Value transformer registry entries must implement %s; got %s.',
                    ValueTransformerInterface::class,
                    get_debug_type($transformer),
                ));
            }

            $class = $transformer::class;

            if (isset($this->transformers[$class])) {
                throw new InvalidArgumentException(sprintf(
                    'Value transformer "%s" is already registered.',
                    $class,
                ));
            }

            $this->transformers[$class] = $transformer;
        }
    }

    public function get(string $transformer): ValueTransformerInterface
    {
        if (! isset($this->transformers[$transformer])) {
            throw new ValueTransformerNotRegistered($transformer);
        }

        $registered = $this->transformers[$transformer];

        if ($registered::class !== $transformer) {
            throw new LogicException(sprintf(
                'Value transformer registry returned %s for requested transformer "%s".',
                $registered::class,
                $transformer,
            ));
        }

        return $registered;
    }
}
