<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Exception;

/**
 * `bypassFinals()` cannot do what was asked of it.
 *
 * @api
 */
final class BypassUnavailable extends \LogicException implements UnderstudyError
{
    /**
     * Builds the error for a class loaded before bypass could be installed.
     *
     * @param class-string $class
     */
    public static function alreadyLoaded(string $class): self
    {
        return new self(sprintf(
            "`%s` is already loaded, so its `final` cannot be lifted any more.\n"
            . "A class is read from disk once per process, and the transform happens as it is read.\n"
            . "- Call Understudy::bypassFinals() from your test bootstrap, before Composer autoloads it.\n"
            . '- Or double an interface it implements, which needs no bypass at all.',
            $class,
        ));
    }

    /**
     * Builds the error for a target that is not a class.
     *
     * @param class-string $class
     */
    public static function notAClass(string $class, string $kind): self
    {
        return new self(sprintf(
            "`%s` is %s, and lifting `final` would not make it extendable.\n"
            . 'Bypass exists for a final *class* standing between a test and the code under test.',
            $class,
            $kind,
        ));
    }

    /**
     * Builds the error for an already-installed foreign source wrapper.
     *
     * Something else already transforms PHP source on `file://`; replacing it
     * would silently disable whatever it does.
     */
    public static function foreignWrapper(string $owner): self
    {
        return new self(sprintf(
            "Something else is already transforming PHP source read through `file://` (%s).\n"
            . "Replacing that wrapper would silently disable whatever it does, so understudy will not.\n"
            . "- Call Understudy::bypassFinals() before the other wrapper is installed.\n"
            . '- Or double an interface, which needs no wrapper at all.',
            $owner,
        ));
    }
}
