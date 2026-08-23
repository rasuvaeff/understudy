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
}
