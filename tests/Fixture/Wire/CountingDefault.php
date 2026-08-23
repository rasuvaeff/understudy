<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Wire;

final class CountingDefault
{
    public static int $constructed = 0;

    public function __construct()
    {
        ++self::$constructed;
    }
}
