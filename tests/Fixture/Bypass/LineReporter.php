<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Bypass;

/**
 * The line of the `throw` below is the assertion.
 *
 * Stripping `final` must not move it: a stack trace or a coverage report one
 * line off is worse than the double space the stripper leaves behind instead.
 */
final class LineReporter
{
    public const int THROW_LINE = 19;

    public function fail(): never
    {
        throw new \RuntimeException('reported');
    }
}
