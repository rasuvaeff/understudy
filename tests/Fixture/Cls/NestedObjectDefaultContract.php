<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Cls;

/**
 * An object built inside an array default. It does not start with `new`, so a
 * check that only looked at the first token would send it down the evaluating
 * path — and `getDefaultValue()` runs every constructor in there.
 */
interface NestedObjectDefaultContract
{
    public function batched(array $stamps = [new CountingStamp(3)]): int;
}
