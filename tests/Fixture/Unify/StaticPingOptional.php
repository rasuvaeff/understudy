<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

class StaticPingOptional
{
    public static function ping(string $value = "", int $extra = 0): int
    {
        return strlen($value) + $extra;
    }
}
