<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface StaticOptionalContract
{
    public static function ping(string $value = ""): int;
}
