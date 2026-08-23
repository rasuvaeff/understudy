<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

class StaticPingByValueSlot
{
    public static function ping(array $slot): int
    {
        return count($slot);
    }
}
