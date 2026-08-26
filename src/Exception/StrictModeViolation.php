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
     * `$refusal` carries the call and the expectations that did not accept it,
     * already rendered. It is empty when nothing at all was configured for the
     * method: there is then nothing to compare the call against, and an empty
     * list under a heading would be an answer pretending to be one.
     *
     * @param non-empty-string $label
     * @param non-empty-string $method
     */
    public static function unexpectedCall(string $label, string $method, string $refusal = ''): self
    {
        $head = sprintf('Understudy `%s` is strict and received an unexpected call to `%s()`.', $label, $method);
        $hint = sprintf('Configure it first: when(fn () => $double->%s(...))->returns(...)', $method);

        return new self($refusal === ''
            ? $head . "\n" . $hint
            : $head . "\n\n" . $refusal . "\n\n" . $hint);
    }
}
