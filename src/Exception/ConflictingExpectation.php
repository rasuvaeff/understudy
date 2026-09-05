<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Exception;

/**
 * A `when()` or `expect()` names a call another registration already
 * specifies, and the two would not compose: whichever is declared later takes
 * the dispatch, and the earlier one silently loses its purpose — a stub's
 * answer is replaced by the mode default, or a count is starved and reported
 * as "called never" about a call that did happen.
 *
 * Refused at registration, because that is where the mistake is made: the
 * dispatch semantics stay exactly as documented, and the only layering this
 * closes is the one with no working reading. Two plain stubs for one call are
 * still the documented "most recently registered wins" arrangement.
 *
 * @api
 */
final class ConflictingExpectation extends \LogicException implements UnderstudyError
{
    /**
     * Builds the error for a claim registered after an identical stub.
     *
     * @param non-empty-string $label
     * @param non-empty-string $spec
     */
    public static function claimAfterStub(string $label, string $spec): self
    {
        return new self(sprintf(
            'Understudy `%s` already has `%s` stubbed, and a separate expect() for the same call would not '
            . 'compose with it: the expectation would take the dispatch and answer the mode default, silently '
            . 'discarding the stub. Declare one expectation — expect(...)->returns(...), or count the stub '
            . 'with when(...)->times(...).',
            $label,
            $spec,
        ));
    }

    /**
     * Builds the error for a stub registered after a counted expectation.
     *
     * @param non-empty-string $label
     * @param non-empty-string $spec
     */
    public static function stubAfterCountedExpectation(string $label, string $spec): self
    {
        return new self(sprintf(
            'Understudy `%s` already counts `%s`, and a later stub for the same call would not compose with '
            . 'it: the stub would take the dispatch and starve the count, which verifyAll() then reports as '
            . '"called never" about a call that did happen. Give the expectation its behaviour instead: '
            . 'expect(...)->returns(...).',
            $label,
            $spec,
        ));
    }

    /**
     * Builds the error for duplicate counted expectations.
     *
     * @param non-empty-string $label
     * @param non-empty-string $spec
     */
    public static function duplicateCountedExpectation(string $label, string $spec): self
    {
        return new self(sprintf(
            'Understudy `%s` already counts `%s`, and a second expectation for the same call would not '
            . 'compose with it: the newer one would take every match and the older count is starved. Declare '
            . 'the count once — expect(...)->times(...).',
            $label,
            $spec,
        ));
    }
}
