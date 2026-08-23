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
}
