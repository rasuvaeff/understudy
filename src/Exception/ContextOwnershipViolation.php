<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Exception;

/**
 * Configuration and verification belong to the context that created a double.
 * Normal calls may cross Fiber boundaries, but mutating another context would
 * make lifecycle verification race with work owned elsewhere.
 *
 * @api
 */
final class ContextOwnershipViolation extends \LogicException implements UnderstudyError
{
    /**
     * A double was configured or verified from a context other than the one
     * that created it — another Fiber, or outside the scope it was built in.
     */
    public static function forDouble(): self
    {
        return new self(
            'This understudy belongs to a different runtime context. '
            . 'Configure and verify it in the scope or Fiber that created it; '
            . 'only normal method calls may cross context boundaries.',
        );
    }
}
