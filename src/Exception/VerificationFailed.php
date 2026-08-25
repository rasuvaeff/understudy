<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Exception;

use Rasuvaeff\Understudy\VerificationFailure;

/**
 * A verification about what the code under test did (or did not) do failed.
 * This is the one exception that reports on the tested code rather than on
 * misuse of Understudy, so adapters map it to a test failure.
 *
 * The message is the rendered report for a human; {@see failures()} carries
 * the same facts as {@see VerificationFailure} records for tooling. Both are
 * built from one list — there is no path where they disagree.
 *
 * @api
 */
final class VerificationFailed extends \RuntimeException implements UnderstudyError
{
    /**
     * @param list<VerificationFailure> $failures
     */
    private function __construct(
        string $message,
        private readonly array $failures,
    ) {
        parent::__construct($message);
    }

    /**
     * @param non-empty-list<VerificationFailure> $failures
     */
    public static function of(array $failures): self
    {
        return new self(
            implode("\n\n", array_map(
                static fn(VerificationFailure $failure): string => $failure->summary,
                $failures,
            )),
            $failures,
        );
    }

    /**
     * Every failure this exception reports, in the order the message states
     * them. Non-empty whenever this exception was thrown by Understudy.
     *
     * @return list<VerificationFailure>
     */
    public function failures(): array
    {
        return $this->failures;
    }
}
