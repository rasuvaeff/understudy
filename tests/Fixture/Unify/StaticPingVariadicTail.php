<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

class StaticPingVariadicTail
{
    public static function ping(string ...$parts): int
    {
        return count($parts);
    }
}
