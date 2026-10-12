<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Exception;

/**
 * A forwarded call returned an object the double cannot stand in for.
 *
 * @api
 */
final class OriginalReturnTypeViolation extends \RuntimeException implements UnderstudyError
{
    /**
     * Builds the error for a forwarded fluent call returning another object.
     *
     * @param non-empty-string $label
     * @param non-empty-string $method
     */
    public static function foreignInstance(string $label, string $method, string $returned): self
    {
        return new self(sprintf(
            "Understudy `%s` forwarded `%s()`, which returned a different `%s` instead of the instance it "
            . "was called on.\n"
            . 'The method declares `static`, so the double must return itself, and another object of the '
            . 'real class is not a double. Configure the method instead: '
            . 'when(fn () => $double->%s(...))->returns($double).',
            $label,
            $method,
            $returned,
            $method,
        ));
    }

    /**
     * Builds the error for an inline call whose real body built a new instance
     * of the double's own class — a `new static`.
     *
     * It is of the generated class, so it satisfies the override's `: static`
     * and the language lets it through; no context knows it, so every call on
     * it would fail as a double that was never registered. A `clone $this`
     * does not reach here: the copy inherits the original's forwarding and
     * answers for real.
     *
     * @param non-empty-string $label  the double's own name in reports
     * @param non-empty-string $method the method whose real body returned it
     */
    public static function foreignSelf(string $label, string $method): self
    {
        return new self(sprintf(
            "Understudy `%s` ran `%s()` inline, and the real body built a new instance of itself — a "
            . "`new static` or `new self`.\n"
            . 'It is of the double\'s own class, so the return type accepts it, but no context knows it and '
            . 'every call on it would fail as an unregistered double. Configure the method instead: '
            . 'when(fn () => $double->%s(...))->returns($double).',
            $label,
            $method,
            $method,
        ));
    }
}
