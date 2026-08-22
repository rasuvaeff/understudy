<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Exception;

/**
 * A strict understudy received a call no expectation matched.
 *
 * @api
 */
final class StrictModeViolation extends \RuntimeException implements UnderstudyError
{
    /**
     * @param non-empty-string $label
     * @param non-empty-string $method
     */
    public static function unexpectedCall(string $label, string $method): self
    {
        return new self(sprintf(
            "Understudy `%s` is strict and received an unexpected call to `%s()`.\n"
            . 'Configure it first: when(fn () => $double->%s(...))->returns(...)',
            $label,
            $method,
            $method,
        ));
    }
}
