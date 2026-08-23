<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

class StaticPingProtected
{
    protected static function ping(string $value): int
    {
        return strlen($value);
    }
}
