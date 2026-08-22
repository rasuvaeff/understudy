<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Matcher;

/**
 * @internal
 */
final readonly class IntInRange implements ArgumentMatcher
{
    public function __construct(
        private ?int $min = null,
        private ?int $max = null,
    ) {}

    #[\Override]
    public function matches(mixed $argument): bool
    {
        // is_int, not is_numeric: '5' is a string argument, and treating it as
        // a match would hide exactly the bug a strict test is looking for.
        return is_int($argument)
            && ($this->min === null || $argument >= $this->min)
            && ($this->max === null || $argument <= $this->max);
    }

    #[\Override]
    public function describe(): string
    {
        return 'int(' . Bounds::describe($this->min, $this->max) . ')';
    }
}
