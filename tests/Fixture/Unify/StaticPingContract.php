<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface StaticPingContract
{
    public static function ping(string $value): int;
}
