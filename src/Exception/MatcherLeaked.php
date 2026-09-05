<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Exception;

/**
 * A matcher reached a real call instead of a specification closure. Matchers
 * are part of the protocol that records what a call should look like; the code
 * under test must never receive one as a value.
 *
 * @api
 */
final class MatcherLeaked extends \LogicException implements UnderstudyError
{
    /**
     * Builds the error for a matcher used in a real call.
     *
     * @param non-empty-string $method
     * @param non-empty-string $matcher
     */
    public static function intoRealCall(string $method, int $position, string $matcher): self
    {
        return new self(sprintf(
            "Argument #%d of `%s()` was given the matcher `%s` during a real call.\n"
            . 'Matchers belong inside when()/verify()/calls(), not in the call the code under test makes.',
            $position + 1,
            $method,
            $matcher,
        ));
    }
}
