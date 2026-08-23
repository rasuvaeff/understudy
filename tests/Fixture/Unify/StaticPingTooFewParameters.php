<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

class StaticPingTooFewParameters
{
    public static function ping(): int
    {
        return 0;
    }
}
