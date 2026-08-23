<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

class StaticPingFixedThenTail
{
    public static function ping(string $first, int ...$rest): int
    {
        return strlen($first) + array_sum($rest);
    }
}
