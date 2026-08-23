<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Bypass;

require __DIR__ . '/included.php';

/**
 * Reports what the file thinks it is. A temp-copy transform would answer with
 * the copy's path, and the relative `require` above would not have resolved at
 * all.
 */
final class PathReporter
{
    public static function file(): string
    {
        return __FILE__;
    }

    public static function directory(): string
    {
        return __DIR__;
    }

    public static function included(): string
    {
        return INCLUDED_MARKER;
    }
}
