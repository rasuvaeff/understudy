<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface StaticIntPartsContract
{
    public static function ping(int $first, string $second, string $third): int;
}
