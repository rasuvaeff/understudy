<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface StaticUnionReturnContract
{
    public static function ping(): int|string;
}
