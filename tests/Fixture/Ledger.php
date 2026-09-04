<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture;

/**
 * One literal of any scalar type in, one int out — so a property can read
 * which expectation answered straight off the return value, for keys the
 * dispatch index has to discriminate by identity rather than by shape.
 *
 * @internal
 */
interface Ledger
{
    public function at(mixed $value): int;
}
