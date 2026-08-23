<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

class StaticPingExtraRequired
{
    public static function ping(string $value, int $extra): int
    {
        return strlen($value) + $extra;
    }
}
