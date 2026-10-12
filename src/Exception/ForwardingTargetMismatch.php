<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Exception;

/**
 * The instance offered as a forwarding target does not satisfy what the double
 * stands in for.
 *
 * @api
 */
final class ForwardingTargetMismatch extends \InvalidArgumentException implements UnderstudyError
{
    /**
     * Builds the error for forwarding to another understudy.
     *
     * @param non-empty-string $label
     */
    public static function understudyTarget(string $label): self
    {
        return new self(sprintf(
            "Understudy `%s` cannot forward to an understudy.\n"
            . 'Every call would come straight back to a dispatcher, and an unmatched one would keep coming '
            . 'back until the stack runs out. Forward to the real object instead.',
            $label,
        ));
    }

    /**
     * Builds the error for a forwarding target missing a contract.
     *
     * @param non-empty-string $label
     * @param class-string     $contract
     */
    public static function missingContract(string $label, string $contract, string $given): self
    {
        return new self(sprintf(
            "Understudy `%s` stands in for `%s`, and `%s` is not one.\n"
            . 'A forwarding target has to satisfy every contract of the double, or a call the double '
            . 'accepts would not exist on the instance it delegates to.',
            $label,
            $contract,
            $given,
        ));
    }

    /**
     * Builds the error for re-pointing a double that already forwards inline.
     *
     * The state of the first donor was copied onto the double, and a copy
     * cannot be taken twice: a `readonly` property is initialized, and
     * whatever the inline calls have written since would be overwritten by a
     * second donor's reading. Re-pointing is refused rather than silently
     * leaving the double running the first donor's state against the second
     * one's instance.
     *
     * @param non-empty-string $label   the double's own name in reports
     * @param class-string     $current the class whose bodies the double already runs
     * @param class-string     $given   the class of the instance offered instead
     */
    public static function alreadyInline(string $label, string $current, string $given): self
    {
        return new self(sprintf(
            "Understudy `%s` already runs the bodies of `%s` on itself, and cannot be re-pointed at `%s`.\n"
            . 'The first instance\'s state was copied onto the double when forwarding was turned on; a second '
            . 'copy would overwrite everything the real code has written since, and a `readonly` property '
            . 'cannot be written twice at all. Build a second double for the other instance.',
            $label,
            $current,
            $given,
        ));
    }
}
