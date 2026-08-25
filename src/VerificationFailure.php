<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy;

/**
 * The structured half of one verification failure — the same facts the
 * rendered message states, addressable by field.
 *
 * For tooling that wants to do something with a failure other than print it:
 * a runner adapter, an IDE plugin, a report aggregator. The rendered message
 * stays the human surface; this class is the machine one.
 *
 * Which fields are set depends on the kind:
 *
 * | Kind | `double` | `expectation` | `expectedMinimum`/`expectedMaximum` | `actualCount` | `observedCalls` | `expectedCalls` |
 * |---|---|---|---|---|---|---|
 * | `UnmetExpectation` | label | call spec | the claimed bounds | matched calls | calls to the same method | — |
 * | `StrictStubUnused` | label | call spec | — | `0` | — | — |
 * | `OutOfOrder` | label | the call that came first | — | — | — | — |
 * | `OutOfSequence` | — | the mismatched position | — | calls made | the whole protocol | the specified sequence |
 * | `UnaccountedCalls` | label | — | — | unaccounted calls | the unaccounted calls | — |
 * | `UnusedDouble` | label | — | `0`/`0` | calls received | every call | — |
 *
 * The readonly fields of this class, of {@see FailureKind}, and of the
 * exceptions carrying them are frozen public API from v0.1.0: renaming,
 * removing or retyping any of them is a major-version change. New kinds and
 * newly-populated fields are additive and may arrive in a minor.
 *
 * @api
 */
final readonly class VerificationFailure
{
    /**
     * @param string|null             $double           the label of the double the claim is about
     * @param string|null             $expectation      the call specification, as failure messages render it
     * @param int<0, max>|null        $expectedMinimum
     * @param int<0, max>|null        $expectedMaximum
     * @param int<0, max>|null        $actualCount
     * @param list<Invocation>|null   $observedCalls    the calls the rendered report shows
     * @param list<string>|null       $expectedCalls    the specified sequence, for `OutOfSequence`
     * @param non-empty-string        $summary          this failure's rendered text; the exception's
     *                                                  message is these joined with a blank line
     */
    public function __construct(
        public FailureKind $kind,
        public string $summary,
        public ?string $double = null,
        public ?string $expectation = null,
        public ?int $expectedMinimum = null,
        public ?int $expectedMaximum = null,
        public ?int $actualCount = null,
        public ?array $observedCalls = null,
        public ?array $expectedCalls = null,
    ) {}
}
