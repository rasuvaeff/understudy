<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Exception;

/**
 * `callOriginal()` was asked to delegate, and there is nothing to delegate to.
 *
 * @api
 */
final class OriginalCallUnavailable extends \LogicException implements UnderstudyError
{
    /**
     * Builds the error for forwarding without a real instance.
     *
     * @param non-empty-string $label
     */
    public static function forMode(string $label): self
    {
        return new self(sprintf(
            "Understudy `%s` was asked to forward, and has no real instance to forward to.\n"
            . 'Pass one: Understudy::forwarding($double, $real). The shorthand '
            . 'Understudy::forwarding($double) only turns the mode on for a double built from an '
            . 'instance with Understudy::for($real).',
            $label,
        ));
    }

    /**
     * Builds the error for delegating a call without a target.
     *
     * @param non-empty-string $label
     * @param non-empty-string $method
     */
    public static function withoutTarget(string $label, string $method): self
    {
        return new self(sprintf(
            "Understudy `%s` has no real instance to delegate `%s()` to.\n"
            . 'Give it one: Understudy::forwarding($double, $real) — calling the parent implementation on a '
            . 'double whose constructor never ran is not a safe substitute.',
            $label,
            $method,
        ));
    }
}
