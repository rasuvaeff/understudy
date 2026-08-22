<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Matcher;

/**
 * @internal
 */
final readonly class FloatInRange implements ArgumentMatcher
{
    public function __construct(
        private ?float $min = null,
        private ?float $max = null,
    ) {}

    #[\Override]
    public function matches(mixed $argument): bool
    {
        // An int argument is not a float here, for the same reason '5' is not
        // an int: the point of the matcher is to pin the declared type.
        return is_float($argument)
            && ($this->min === null || $argument >= $this->min)
            && ($this->max === null || $argument <= $this->max);
    }

    #[\Override]
    public function describe(): string
    {
        return 'float(' . Bounds::describe($this->min, $this->max) . ')';
    }
}
