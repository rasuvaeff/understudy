<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy;

/**
 * What kind of verification claim a {@see VerificationFailure} reports.
 *
 * @api
 */
enum FailureKind
{
    /** An `expect()` or `verify()` claim about how often a call happens was not met. */
    case UnmetExpectation;

    /** `verifyAll(strictStubs: true)` found a stub the code under test never used. */
    case StrictStubUnused;

    /** An `expect(...)->ordered()` constraint was violated. */
    case OutOfOrder;

    /** `verifySequence()` saw a different protocol than the one specified. */
    case OutOfSequence;

    /** A call arrived that no `expect()` matched and no successful `verify()` claimed. */
    case UnaccountedCalls;

    /** `unused()` found calls on a double expected to receive none. */
    case UnusedDouble;
}
