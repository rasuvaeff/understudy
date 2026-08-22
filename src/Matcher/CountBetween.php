<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Matcher;

/**
 * @internal
 */
final readonly class CountBetween implements ArgumentMatcher
{
    /**
     * @param int<0, max>|null $minimum
     * @param int<0, max>|null $maximum
     */
    public function __construct(
        private ?int $minimum = null,
        private ?int $maximum = null,
    ) {}

    #[\Override]
    public function matches(mixed $argument): bool
    {
        if (!is_array($argument) && !$argument instanceof \Countable) {
            return false;
        }

        $count = count($argument);

        return ($this->minimum === null || $count >= $this->minimum)
            && ($this->maximum === null || $count <= $this->maximum);
    }

    #[\Override]
    public function describe(): string
    {
        return 'count(' . Bounds::describe($this->minimum, $this->maximum) . ')';
    }
}
