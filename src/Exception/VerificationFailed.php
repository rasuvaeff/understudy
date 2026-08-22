<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Exception;

/**
 * A verification about what the code under test did (or did not) do failed.
 * This is the one exception that reports on the tested code rather than on
 * misuse of Understudy, so adapters map it to a test failure.
 *
 * @api
 */
final class VerificationFailed extends \RuntimeException implements UnderstudyError
{
    public static function withReport(string $report): self
    {
        return new self($report);
    }
}
