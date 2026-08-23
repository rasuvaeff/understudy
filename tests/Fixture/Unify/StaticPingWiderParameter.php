<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

class StaticPingWiderParameter
{
    public static function ping(string|int $value): int
    {
        return is_int($value) ? $value : strlen($value);
    }
}
