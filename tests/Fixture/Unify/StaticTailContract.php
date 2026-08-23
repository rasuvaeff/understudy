<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface StaticTailContract
{
    public static function ping(string ...$parts): int;
}
