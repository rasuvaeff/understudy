<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface StaticStringReturnContract
{
    public static function ping(string $value): string;
}
