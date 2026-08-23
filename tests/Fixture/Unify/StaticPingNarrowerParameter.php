<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

class StaticPingNarrowerParameter
{
    public static function ping(int $value): int
    {
        return $value;
    }
}
