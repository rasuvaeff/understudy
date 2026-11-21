<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy;

use Rasuvaeff\Understudy\Exception\VerificationFailed;
use Rasuvaeff\Understudy\Expectation\ArgumentFormatter;
use Rasuvaeff\Understudy\Expectation\Expectation;
use Rasuvaeff\Understudy\Runtime\DoubleState;
use Rasuvaeff\Understudy\Runtime\Runtime;
use Rasuvaeff\Understudy\Runtime\RuntimeContext;

/**
 * Decides what the facade's verification calls report: unmet and unused
 * expectations, ordering, an unfinished armed protocol, unaccounted calls and
 * sequence mismatches — each failure built inside its own alias table.
 *
 * @internal
 */
final class Verifier
{
    private function __construct() {}

    /**
     * Raises one report over the given contexts, or returns how many checks
     * they had verified.
     *
     * @param list<RuntimeContext> $contexts
     * @return int<0, max>
     */
    public static function report(array $contexts, bool $strictStubs): int
    {
        $checks = 0;

        // One alias table for the whole report, not one per failure:
        // `VerificationFailed` joins the summaries into a single message, and
        // an object numbered in the first summary has to keep that number in
        // the third — otherwise two `Book#1` on one screen mean two objects.
        $failures = ArgumentFormatter::scope(
            static function () use ($contexts, $strictStubs, &$checks): array {
                $failures = [];

                foreach ($contexts as $context) {
                    [$contextFailures, $contextChecks] = self::failuresIn($context, $strictStubs);

                    $checks += $contextChecks;
                    $failures = [...$failures, ...$contextFailures];
                }

                return $failures;
            },
        );

        if ($failures !== []) {
            throw VerificationFailed::of($failures);
        }

        return $checks;
    }

    /**
     * Everything one context has to answer for — its expectations, its
     * ordering and its armed protocol — and how many checks that came to.
     *
     * @return array{list<VerificationFailure>, int<0, max>}
     */
    private static function failuresIn(RuntimeContext $context, bool $strictStubs): array
    {
        $contextFailures = [];
        $checks = 0;

        foreach ($context->allStates() as $state) {
            foreach ($state->expectations() as $expectation) {
                // Counted by what is checked, not by what passed: a claim is
                // one check whether it is met or (below) produced the
                // failure, because the number describes the size of the
                // verification — and a failed one throws before the number
                // is returned anyway.
                $checks += $expectation->cardinality() !== null || $strictStubs ? 1 : 0;

                $failure = self::checkExpectation($state, $expectation, $strictStubs);

                if ($failure instanceof \Rasuvaeff\Understudy\VerificationFailure) {
                    $contextFailures[] = $failure;
                }
            }
        }

        $failures = array_reverse($contextFailures);

        // Ordering is read from the sequence counter, which is per context.
        // Comparing across contexts would compare two unrelated countings, so
        // each context answers for its own order.
        $ordered = self::orderedExpectations(context: $context);
        $checks += $ordered === [] ? 0 : 1;

        $outOfOrder = self::orderingFailure($ordered);

        if ($outOfOrder instanceof \Rasuvaeff\Understudy\VerificationFailure) {
            $failures[] = $outOfOrder;
        }

        // An armed protocol guards the order *and* claims the calls. Without
        // this half, arming one and never exercising it — the subject stopped
        // after step two, or a `catch` inside it swallowed the refusal —
        // would pass in silence. Whether one is armed is the check; a
        // complete protocol is a check that passed, an unfinished one the
        // failure below.
        if ($context->armed instanceof \Rasuvaeff\Understudy\Runtime\ArmedSequence) {
            $checks++;
        }

        $unfinished = self::checkArmedSequence($context);

        if ($unfinished instanceof \Rasuvaeff\Understudy\VerificationFailure) {
            $failures[] = $unfinished;
        }

        return [$failures, $checks];
    }

    /**
     * The expectations that declared an order, grouped by double and then
     * sorted back into the order they were actually written: interleaving two
     * doubles is exactly when ordering claims are worth making.
     *
     * @param DoubleState|null     $only    one understudy's ordering, or null for every live one
     * @param RuntimeContext|null  $context the context to read, or null for the current one
     *
     * @return list<array{DoubleState, Expectation}>
     */
    public static function orderedExpectations(?DoubleState $only = null, ?RuntimeContext $context = null): array
    {
        /** @var list<array{DoubleState, Expectation}> $ordered */
        $ordered = [];

        foreach (($context ?? Runtime::current())->allStates() as $state) {
            if ($only instanceof \Rasuvaeff\Understudy\Runtime\DoubleState && $state !== $only) {
                continue;
            }

            foreach ($state->declaredExpectations() as $expectation) {
                if ($expectation->isOrdered()) {
                    $ordered[] = [$state, $expectation];
                }
            }
        }

        usort(
            $ordered,
            /**
             * @param array{DoubleState, Expectation} $left
             * @param array{DoubleState, Expectation} $right
             */
            static fn(array $left, array $right): int
                => $left[1]->declarationOrder() <=> $right[1]->declarationOrder(),
        );

        return $ordered;
    }

    /**
     * Ordered expectations must be satisfied in the order they were declared,
     * relative to each other. Calls in between are none of their business —
     * `verifySequence()` is the tool for an exact protocol.
     *
     * @param list<array{DoubleState, Expectation}> $ordered
     */
    public static function orderingFailure(array $ordered): ?VerificationFailure
    {
        // The previous expectation is described a loop turn before the message
        // that quotes it, so the whole walk shares one alias table: described
        // in a scope of its own, an object in `$previousLabel` would start
        // numbering again and two different instances would both read `#1`.
        return ArgumentFormatter::scope(static function () use ($ordered): ?VerificationFailure {
            $previousLast = 0;
            $previousLabel = null;

            foreach ($ordered as [$state, $expectation]) {
                $sequences = $expectation->matchedSequences();

                if ($sequences === []) {
                    continue;
                }

                $first = min($sequences);

                if ($previousLabel !== null && $first < $previousLast) {
                    return new VerificationFailure(
                        kind: FailureKind::OutOfOrder,
                        summary: sprintf(
                            "Understudy `%s` expected `%s` to be called after `%s`, but it happened first.",
                            $state->label(),
                            $expectation->describe(),
                            $previousLabel,
                        ),
                        double: $state->label(),
                        expectation: $expectation->describe(),
                    );
                }

                $previousLast = max($sequences);
                $previousLabel = $expectation->describe();
            }

            return null;
        });
    }

    private static function checkArmedSequence(RuntimeContext $context): ?VerificationFailure
    {
        $sequence = $context->armed;

        if (!$sequence instanceof \Rasuvaeff\Understudy\Runtime\ArmedSequence || $sequence->isComplete()) {
            return null;
        }

        $pending = $sequence->pending();
        \assert($pending instanceof \Rasuvaeff\Understudy\Expectation\Expectation);

        return ArgumentFormatter::scope(static fn(): VerificationFailure => new VerificationFailure(
            kind: FailureKind::OutOfSequence,
            summary: sprintf(
                "The armed protocol stopped at step %d of %d: `%s` was never called.\n\nThe protocol was:\n%s",
                $sequence->position(),
                $sequence->length(),
                $pending->describe(),
                implode("\n", array_map(
                    static fn(int $index, string $step): string => sprintf('    %d. %s', $index + 1, $step),
                    array_keys($sequence->describe()),
                    $sequence->describe(),
                )),
            ),
            expectation: $pending->describe(),
            expectedCalls: $sequence->describe(),
        ));
    }

    public static function checkExpectation(DoubleState $state, Expectation $expectation, bool $strictStubs): ?VerificationFailure
    {
        $cardinality = $expectation->cardinality();
        $count = $expectation->matchCount();

        if (!$cardinality instanceof \Rasuvaeff\Understudy\Cardinality) {
            // A plain stub is permission, not a claim — unless strict stubs
            // turn "configured but never used" into a failure of its own.
            return $strictStubs && $count === 0
                ? ArgumentFormatter::scope(static fn(): VerificationFailure => new VerificationFailure(
                    kind: FailureKind::StrictStubUnused,
                    summary: sprintf(
                        "Understudy `%s` has a stub for `%s` that was never used.\n"
                        . 'Remove it, or drop strictStubs if the call is genuinely optional.',
                        $state->label(),
                        $expectation->describe(),
                    ),
                    double: $state->label(),
                    expectation: $expectation->describe(),
                    actualCount: 0,
                ))
                : null;
        }

        if ($cardinality->allows($count)) {
            return null;
        }

        // The same renderer verify() uses, rather than a sprintf of its own.
        // Two paths reported the same failure and only one of them showed the
        // call log — so an unmet expectation reported by verifyAll(), which is
        // what every runner adapter calls, said "expected 2, got 1" and left
        // the reader to find out which call differed. That log is where the
        // alias table and the argument marks live, and it is the whole reason
        // they exist. The paths also disagreed on wording: this one produced
        // "but it was called never".
        return self::unmetFailure(
            state: $state,
            description: $expectation->describe(),
            cardinality: $cardinality,
            count: $count,
            method: $expectation->method,
            expectedArgs: $expectation->args,
        );
    }

    /**
     * @param non-empty-string $description
     * @param int<0, max>      $count
     * @param non-empty-string $method
     * @param list<mixed>      $expectedArgs
     */
    public static function unmetFailure(
        DoubleState $state,
        string $description,
        Cardinality $cardinality,
        int $count,
        string $method,
        array $expectedArgs,
    ): VerificationFailure {
        $sameMethod = array_values(array_filter(
            $state->callLog(),
            static fn(Invocation $invocation): bool => $invocation->method === $method,
        ));

        return ArgumentFormatter::scope(static fn(): VerificationFailure => new VerificationFailure(
            kind: FailureKind::UnmetExpectation,
            summary: FailureReport::render(
                label: $state->label(),
                expectation: $description,
                cardinality: $cardinality,
                actual: $count,
                callLog: $state->callLog(),
                method: $method,
                expectedArgs: $expectedArgs,
            ),
            double: $state->label(),
            expectation: $description,
            expectedMinimum: $cardinality->minimum,
            expectedMaximum: $cardinality->maximum,
            actualCount: $count,
            observedCalls: $sameMethod,
        ));
    }

    /**
     * The calls nothing claimed, as one failure — or null when every call was
     * accounted for. Built inside the caller's alias scope.
     */
    public static function unaccountedFailure(DoubleState $state): ?VerificationFailure
    {
        $unaccounted = array_values(array_filter(
            $state->callLog(),
            static fn(Invocation $invocation): bool => !$invocation->isAccounted(),
        ));

        if ($unaccounted === []) {
            return null;
        }

        return new VerificationFailure(
            kind: FailureKind::UnaccountedCalls,
            summary: sprintf(
                "Understudy `%s` received %d call(s) nothing accounted for:\n%s",
                $state->label(),
                count($unaccounted),
                FailureReport::renderCallLog($unaccounted),
            ),
            double: $state->label(),
            actualCount: count($unaccounted),
            observedCalls: $unaccounted,
        );
    }

    /**
     * Every logged call the probe matches, in order.
     *
     * @return list<Invocation>
     */
    public static function matching(DoubleState $state, Expectation $probe): array
    {
        return array_values(array_filter(
            $state->callLog(),
            static fn(Invocation $invocation): bool => $probe->matches($invocation->method, $invocation->args),
        ));
    }

    /**
     * @param list<array{object, Expectation}> $probes
     * @param list<Invocation>                 $log
     */
    public static function firstSequenceMismatch(array $probes, array $log): ?VerificationFailure
    {
        return ArgumentFormatter::scope(static function () use ($probes, $log): ?VerificationFailure {
            $expected = array_map(
                static fn(array $probe): string => $probe[1]->describe(),
                $probes,
            );

            if (count($log) !== count($probes)) {
                return ArgumentFormatter::scope(static fn(): VerificationFailure => new VerificationFailure(
                    kind: FailureKind::OutOfSequence,
                    summary: sprintf(
                        "Expected exactly %d call(s) in this order, but %d happened:\n%s",
                        count($probes),
                        count($log),
                        FailureReport::renderCallLog($log),
                    ),
                    actualCount: count($log),
                    observedCalls: $log,
                    expectedCalls: $expected,
                ));
            }

            foreach ($probes as $position => [$double, $probe]) {
                $invocation = $log[$position];

                if (!$invocation->belongsTo($double)) {
                    return ArgumentFormatter::scope(static fn(): VerificationFailure => new VerificationFailure(
                        kind: FailureKind::OutOfSequence,
                        summary: sprintf(
                            "Call #%d was expected to be `%s` on one understudy, but the same call was made on a different understudy.\n\nThe calls made were:\n%s",
                            $position + 1,
                            $probe->describe(),
                            FailureReport::renderCallLog($log),
                        ),
                        expectation: $probe->describe(),
                        actualCount: count($log),
                        observedCalls: $log,
                        expectedCalls: $expected,
                    ));
                }

                if (!$probe->matches($invocation->method, $invocation->args)) {
                    return ArgumentFormatter::scope(static fn(): VerificationFailure => new VerificationFailure(
                        kind: FailureKind::OutOfSequence,
                        summary: sprintf(
                            "Call #%d was expected to be `%s`, but it was `%s`.\n\nThe calls made were:\n%s",
                            $position + 1,
                            $probe->describe(),
                            FailureReport::renderCall($invocation),
                            FailureReport::renderCallLog($log),
                        ),
                        expectation: $probe->describe(),
                        actualCount: count($log),
                        observedCalls: $log,
                        expectedCalls: $expected,
                    ));
                }
            }

            return null;
        });
    }

    /**
     * @return non-empty-string
     */
    public static function describeOutcome(Invocation $invocation): string
    {
        if ($invocation->didThrow()) {
            $thrown = $invocation->thrown();
            \assert($thrown instanceof \Throwable);

            return 'threw ' . $thrown::class;
        }

        if ($invocation->isReturnDiscarded()) {
            return 'returned (value not kept: lean)';
        }

        return 'returned ' . ArgumentFormatter::format($invocation->returned());
    }
}
