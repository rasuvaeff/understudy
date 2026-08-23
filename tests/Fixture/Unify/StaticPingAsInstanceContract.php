<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface StaticPingAsInstanceContract
{
    public function ping(string $value): int;
}
