<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

class StaticPingTypedReturn
{
    public static function ping(): int
    {
        return 1;
    }
}
