<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface StaticMixedPartsContract
{
    public static function ping(string $first, int $second): int;
}
