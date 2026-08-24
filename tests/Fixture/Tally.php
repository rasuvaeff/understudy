<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture;

/**
 * One int in, one int out — so a property can read which expectation answered
 * straight off the return value.
 *
 * @internal
 */
interface Tally
{
    public function score(int $value): int;
}
