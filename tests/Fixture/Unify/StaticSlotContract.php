<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface StaticSlotContract
{
    public static function ping(array &$slot): int;
}
