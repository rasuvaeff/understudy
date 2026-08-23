<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

class StaticPingFixedInsteadOfTail
{
    public static function ping(string $parts): int
    {
        return strlen($parts);
    }
}
