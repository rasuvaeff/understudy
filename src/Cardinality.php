<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy;

use Rasuvaeff\Understudy\Exception\InvalidSpecificationArgument;

/**
 * How many times a call is allowed to happen. `null` as the maximum means no
 * upper bound.
 *
 * @api
 */
final readonly class Cardinality
{
    /**
     * @param int<0, max>      $minimum
     * @param int<0, max>|null $maximum
     */
    private function __construct(
        public int $minimum,
        public ?int $maximum,
    ) {
        if ($maximum !== null && $maximum < $minimum) {
            throw InvalidSpecificationArgument::maximumBelowMinimum($minimum, $maximum);
        }
    }

    public static function exactly(int $times): self
    {
        $bounded = self::nonNegative($times);

        return new self($bounded, $bounded);
    }

    public static function atLeast(int $minimum): self
    {
        return new self(self::nonNegative($minimum), null);
    }

    public static function between(int $minimum, ?int $maximum): self
    {
        return new self(
            self::nonNegative($minimum),
            $maximum === null ? null : self::nonNegative($maximum),
        );
    }

    /**
     * Counts arrive from user code, so the bound is checked rather than
     * assumed from the docblock.
     *
     * @return int<0, max>
     */
    private static function nonNegative(int $count): int
    {
        if ($count < 0) {
            throw InvalidSpecificationArgument::negativeCount($count);
        }

        return $count;
    }

    /**
     * Any number of calls, including none.
     */
    public static function any(): self
    {
        return new self(0, null);
    }

    public static function never(): self
    {
        return new self(0, 0);
    }

    /**
     * @param int<0, max> $count
     */
    public function allows(int $count): bool
    {
        return $count >= $this->minimum
            && ($this->maximum === null || $count <= $this->maximum);
    }

    /**
     * @return non-empty-string
     */
    public function describe(): string
    {
        return match (true) {
            $this->minimum === 0 && $this->maximum === 0 => 'never',
            $this->minimum === 0 && $this->maximum === null => 'any number of times',
            $this->maximum === null => 'at least ' . $this->times($this->minimum),
            $this->minimum === $this->maximum => 'exactly ' . $this->times($this->minimum),
            default => sprintf('between %d and %s', $this->minimum, $this->times($this->maximum)),
        };
    }

    /**
     * @param int<0, max> $count
     *
     * @return non-empty-string
     */
    private function times(int $count): string
    {
        return $count === 1 ? '1 time' : $count . ' times';
    }
}
