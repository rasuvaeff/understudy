<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Exception;

/**
 * A loose understudy had to answer a call, but the declared return type has no
 * safe default. Understudy never invents one by calling an arbitrary
 * constructor and never hands back an uninitialised object.
 *
 * @api
 */
final class NoDefaultValue extends \RuntimeException implements UnderstudyError
{
    /**
     * @param non-empty-string $label
     * @param non-empty-string $method
     */
    public static function forReturnType(string $label, string $method, string $type): self
    {
        return new self(sprintf(
            "Understudy `%s` cannot answer `%s()`: there is no safe default for the declared return type `%s`.\n"
            . "- Configure the call: when(fn () => \$double->%s(...))->returns(...)\n"
            . '- Or register a default for the type: Understudy::defaults(%s::class, fn () => ...)',
            $label,
            $method,
            $type,
            $method,
            $type,
        ));
    }
}
