<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Defaults;

/**
 * Counts its constructions, so a test can see a default being evaluated.
 */
final class Tally
{
    public static int $built = 0;

    public function __construct()
    {
        self::$built++;
    }
}
