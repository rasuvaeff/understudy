<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Cls;

/**
 * Counts its own construction, so a test can prove that building a double did
 * not evaluate a default that would have built one.
 */
final class CountingStamp
{
    public static int $constructed = 0;

    public function __construct(public int $at = 0)
    {
        ++self::$constructed;
    }
}
