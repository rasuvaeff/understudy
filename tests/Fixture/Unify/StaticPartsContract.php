<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface StaticPartsContract
{
    public static function ping(string $first, string $second): int;
}
