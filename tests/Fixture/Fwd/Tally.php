<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Fwd;

/**
 * The quiet half of the clone-adoption fixtures: every property has a
 * declared default, so a double built without a constructor is readable
 * rather than uninitialized. A copy of such a double adopted on another
 * double's behalf raises nothing at all — it computes over a state the test
 * never set up, which is why class identity is not enough to adopt a clone.
 */
class Tally
{
    public int $count = 7;

    public function __construct(int $count)
    {
        $this->count = $count;
    }

    public function total(): int
    {
        return $this->count * 10;
    }

    /** Clones the double it was handed, not itself. */
    public function mergeWith(self $other): int
    {
        $copy = clone $other;

        return $copy->total();
    }
}
