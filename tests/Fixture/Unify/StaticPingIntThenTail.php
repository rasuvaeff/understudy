<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

class StaticPingIntThenTail
{
    public static function ping(int $first, string ...$rest): int
    {
        return $first + count($rest);
    }
}
