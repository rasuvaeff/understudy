<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy;

use Rasuvaeff\Understudy\Codegen\DoubleFactory;
use Rasuvaeff\Understudy\Exception\ContextOwnershipViolation;
use Rasuvaeff\Understudy\Exception\InvalidCallSpecification;
use Rasuvaeff\Understudy\Exception\VerificationFailed;
use Rasuvaeff\Understudy\Expectation\ArgumentFormatter;
use Rasuvaeff\Understudy\Expectation\Expectation;
use Rasuvaeff\Understudy\Runtime\DoubleState;
use Rasuvaeff\Understudy\Runtime\InvocationSignal;
use Rasuvaeff\Understudy\Runtime\Mode;
use Rasuvaeff\Understudy\Runtime\Runtime;

/**
 * The whole public surface, as static methods so that an understudy itself can
 * stay free of service members: every one of them would be a name the doubled
 * contract can no longer use.
 *
 * The same three verbs exist as free functions in this namespace — see
 * `functions.php` — and read better in tests. This class is the collision-free
 * form, which matters in Pest, where `expect()` is already taken.
 *
 * @api
 */
final class Understudy
{
    private function __construct() {}

    /**
     * Creates an understudy for one contract, optionally combined with further
     * interfaces.
     *
     * @template T of object
     *
     * @param class-string<T> $target
     * @param class-string    ...$interfaces
     *
     * @return T
     */
    public static function for(string $target, string ...$interfaces): object
    {
        $contracts = [$target, ...array_values($interfaces)];
        $blueprint = DoubleFactory::blueprintFor($contracts);
        // Reflection rather than `new $class()`: the generated class is not
        // statically known, and this is also the path a class double will need,
        // where the target's constructor must be skipped rather than run.
        /** @var T $double */
        $double = (new \ReflectionClass($blueprint->generatedClass))->newInstanceWithoutConstructor();

        Runtime::adopt($double, new DoubleState($blueprint));

        return $double;
    }

    /**
     * Stubs a call: allowed any number of times, including none.
     *
     * @param callable(): mixed $call
     *
     * @return WhenBuilder<mixed>
     */
    public static function when(callable $call): WhenBuilder
    {
        $signal = self::record($call);
        $expectation = new Expectation($signal->method, $signal->args);
        $state = self::stateOf($signal->double);
        $expectation->setDeclarationOrder(Runtime::current()->nextDeclaration());

        $state->addExpectation($expectation);

        return new WhenBuilder($expectation);
    }

    /**
     * Declares a call the code under test is expected to make: exactly once
     * unless `times()` says otherwise, and checked by `verifyAll()`.
     *
     * @param callable(): mixed $call
     *
     * @return ExpectBuilder<mixed>
     */
    public static function expect(callable $call): ExpectBuilder
    {
        $signal = self::record($call);
        $expectation = new Expectation($signal->method, $signal->args);
        $expectation->setCardinality(Cardinality::exactly(1));
        $expectation->declareClaim();
        $state = self::stateOf($signal->double);
        $expectation->setDeclarationOrder(Runtime::current()->nextDeclaration());

        $state->addExpectation($expectation);

        return new ExpectBuilder($expectation);
    }

    /**
     * Checks every expectation of the current context: the ones `expect()`
     * declared, and the stubs that opted in through `times()`.
     *
     * With `strictStubs`, a stub that was never called fails too — the
     * Mockito reading of "why did you configure it, then?".
     */
    public static function verifyAll(bool $strictStubs = false): void
    {
        $failures = [];

        foreach (Runtime::current()->allStates() as $state) {
            foreach ($state->expectations() as $expectation) {
                $failure = self::checkExpectation($state, $expectation, $strictStubs);

                if ($failure !== null) {
                    $failures[] = $failure;
                }
            }
        }

        $failures = array_reverse($failures);
        $outOfOrder = self::checkOrdering();

        if ($outOfOrder !== null) {
            $failures[] = $outOfOrder;
        }

        if ($failures !== []) {
            throw VerificationFailed::withReport(implode("\n\n", $failures));
        }
    }

    /**
     * Ordered expectations must be satisfied in the order they were declared,
     * relative to each other. Calls in between are none of their business —
     * `verifySequence()` is the tool for an exact protocol.
     *
     * @return non-empty-string|null
     */
    private static function checkOrdering(?DoubleState $only = null): ?string
    {
        /** @var list<array{DoubleState, Expectation}> $ordered */
        $ordered = [];

        foreach (Runtime::current()->allStates() as $state) {
            if ($only !== null && $state !== $only) {
                continue;
            }

            foreach ($state->declaredExpectations() as $expectation) {
                if ($expectation->isOrdered()) {
                    $ordered[] = [$state, $expectation];
                }
            }
        }

        // Grouped by double above, so sort back into the order the
        // expectations were actually written: interleaving two doubles is
        // exactly when ordering claims are worth making.
        usort(
            $ordered,
            /**
             * @param array{DoubleState, Expectation} $left
             * @param array{DoubleState, Expectation} $right
             */
            static fn(array $left, array $right): int
                => $left[1]->declarationOrder() <=> $right[1]->declarationOrder(),
        );

        $previousLast = 0;
        $previousLabel = null;

        foreach ($ordered as [$state, $expectation]) {
            $sequences = $expectation->matchedSequences();

            if ($sequences === []) {
                continue;
            }

            $first = min($sequences);

            if ($previousLabel !== null && $first < $previousLast) {
                return sprintf(
                    "Understudy `%s` expected `%s` to be called after `%s`, but it happened first.",
                    $state->label(),
                    $expectation->describe(),
                    $previousLabel,
                );
            }

            $previousLast = max($sequences);
            $previousLabel = $expectation->describe();
        }

        return null;
    }

    /**
     * @return non-empty-string|null
     */
    private static function checkExpectation(DoubleState $state, Expectation $expectation, bool $strictStubs): ?string
    {
        $cardinality = $expectation->cardinality();
        $count = $expectation->matchCount();

        if ($cardinality === null) {
            // A plain stub is permission, not a claim — unless strict stubs
            // turn "configured but never used" into a failure of its own.
            return $strictStubs && $count === 0
                ? sprintf(
                    "Understudy `%s` has a stub for `%s` that was never used.\n"
                    . 'Remove it, or drop strictStubs if the call is genuinely optional.',
                    $state->label(),
                    $expectation->describe(),
                )
                : null;
        }

        if ($cardinality->allows($count)) {
            return null;
        }

        return sprintf(
            'Understudy `%s` expected `%s` to be called %s, but it was called %s.',
            $state->label(),
            $expectation->describe(),
            $cardinality->describe(),
            $count === 0 ? 'never' : ($count === 1 ? '1 time' : $count . ' times'),
        );
    }

    /**
     * Asserts, after the fact, how many times a call was made.
     *
     * @param callable(): mixed $call
     * @param int<0, max>|null  $times   exact count; defaults to at least one
     * @param int<0, max>|null  $minimum
     * @param int<0, max>|null  $maximum
     */
    public static function verify(
        callable $call,
        ?int $times = null,
        ?int $minimum = null,
        ?int $maximum = null,
        bool $never = false,
    ): void {
        $signal = self::record($call);
        $state = self::stateOf($signal->double);
        $probe = new Expectation($signal->method, $signal->args);

        $matches = 0;
        $matched = [];

        foreach ($state->callLog() as $invocation) {
            if ($probe->matches($invocation->method, $invocation->args)) {
                $matches++;
                $matched[] = $invocation;
            }
        }

        [$low, $high] = match (true) {
            $never => [0, 0],
            $times !== null => [$times, $times],
            default => [$minimum ?? 1, $maximum],
        };

        if ($matches >= $low && ($high === null || $matches <= $high)) {
            // Counting the whole log each time and marking idempotently is
            // what keeps the order of verify() calls from changing anything.
            foreach ($matched as $invocation) {
                $invocation->markAccounted();
            }

            return;
        }

        throw VerificationFailed::withReport(FailureReport::render(
            label: $state->label(),
            expectation: $probe->describe(),
            expectedLow: $low,
            expectedHigh: $high,
            actual: $matches,
            callLog: $state->callLog(),
            method: $signal->method,
            expectedArgs: $signal->args,
        ));
    }

    /**
     * Every recorded call matching the specification, in order.
     *
     * @param callable(): mixed $call
     *
     * @return list<Invocation>
     */
    public static function calls(callable $call): array
    {
        $signal = self::record($call);
        $probe = new Expectation($signal->method, $signal->args);

        return array_values(array_filter(
            self::stateOf($signal->double)->callLog(),
            static fn(Invocation $i): bool => $probe->matches($i->method, $i->args),
        ));
    }

    /**
     * Makes an understudy fail on any call no expectation matched.
     */
    public static function strict(object $double): void
    {
        self::stateOf($double)->setMode(Mode::Strict);
    }

    /**
     * Names one understudy in failure messages, which is what makes two
     * doubles of the same contract tellable apart.
     *
     * @param non-empty-string $label
     */
    public static function label(object $double, string $label): void
    {
        self::stateOf($double)->setLabel($label);
    }

    /**
     * Asserts that nothing was called on this understudy at all.
     */
    public static function unused(object $double): void
    {
        $state = self::stateOf($double);
        $log = $state->callLog();

        if ($log === []) {
            return;
        }

        throw VerificationFailed::withReport(sprintf(
            "Understudy `%s` was expected to be unused, but received %d call(s):\n%s",
            $state->label(),
            count($log),
            FailureReport::renderCallLog($log),
        ));
    }

    /**
     * Asserts that every call this understudy received has been accounted for:
     * matched by an `expect()`, or claimed by a successful `verify()`.
     */
    public static function nothingElse(object $double): void
    {
        $state = self::stateOf($double);

        $unaccounted = array_values(array_filter(
            $state->callLog(),
            static fn(Invocation $invocation): bool => !$invocation->isAccounted(),
        ));

        if ($unaccounted === []) {
            return;
        }

        throw VerificationFailed::withReport(sprintf(
            "Understudy `%s` received %d call(s) nothing accounted for:\n%s",
            $state->label(),
            count($unaccounted),
            FailureReport::renderCallLog($unaccounted),
        ));
    }

    /**
     * Asserts this understudy's expectations are satisfied and that nothing
     * else happened to it — the two halves of "I have described everything".
     */
    public static function allVerified(object $double): void
    {
        $state = self::stateOf($double);

        foreach ($state->expectations() as $expectation) {
            $failure = self::checkExpectation($state, $expectation, strictStubs: false);

            if ($failure !== null) {
                throw VerificationFailed::withReport($failure);
            }
        }

        $outOfOrder = self::checkOrdering($state);

        if ($outOfOrder !== null) {
            throw VerificationFailed::withReport($outOfOrder);
        }

        self::nothingElse($double);
    }

    /**
     * Asserts that these calls are exactly what happened in this context, in
     * this order, across every understudy — no more, no fewer.
     *
     * @param callable(): mixed ...$calls
     */
    public static function verifySequence(callable ...$calls): void
    {
        $log = Runtime::current()->globalLog();
        $probes = [];

        foreach ($calls as $call) {
            $signal = self::record($call);
            self::stateOf($signal->double);
            $probes[] = [$signal->double, new Expectation($signal->method, $signal->args)];
        }

        $mismatch = self::firstSequenceMismatch($probes, $log);

        if ($mismatch !== null) {
            throw VerificationFailed::withReport($mismatch);
        }

        foreach ($log as $invocation) {
            $invocation->markAccounted();
        }
    }

    /**
     * Every call this understudy received, with its arguments and outcome —
     * for reading while a failure is being diagnosed, not for asserting on.
     */
    public static function transcript(object $double): string
    {
        $state = self::stateOf($double);
        $log = $state->callLog();

        if ($log === []) {
            return sprintf('Understudy `%s` received no calls.', $state->label());
        }

        $lines = [sprintf('Understudy `%s` received %d call(s):', $state->label(), count($log))];

        foreach ($log as $invocation) {
            $lines[] = sprintf(
                '  #%d %s -> %s',
                $invocation->sequence,
                FailureReport::renderCall($invocation),
                self::describeOutcome($invocation),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array{object, Expectation}> $probes
     * @param list<Invocation>                 $log
     *
     * @return non-empty-string|null
     */
    private static function firstSequenceMismatch(array $probes, array $log): ?string
    {
        if (count($log) !== count($probes)) {
            return sprintf(
                "Expected exactly %d call(s) in this order, but %d happened:\n%s",
                count($probes),
                count($log),
                FailureReport::renderCallLog($log),
            );
        }

        foreach ($probes as $position => [$double, $probe]) {
            $invocation = $log[$position];

            if (!$invocation->belongsTo($double)) {
                return sprintf(
                    "Call #%d was expected to be `%s` on one understudy, but the same call was made on a different understudy.\n\nThe calls made were:\n%s",
                    $position + 1,
                    $probe->describe(),
                    FailureReport::renderCallLog($log),
                );
            }

            if (!$probe->matches($invocation->method, $invocation->args)) {
                return sprintf(
                    "Call #%d was expected to be `%s`, but it was `%s`.\n\nThe calls made were:\n%s",
                    $position + 1,
                    $probe->describe(),
                    FailureReport::renderCall($invocation),
                    FailureReport::renderCallLog($log),
                );
            }
        }

        return null;
    }

    /**
     * @return non-empty-string
     */
    private static function describeOutcome(Invocation $invocation): string
    {
        if ($invocation->didThrow()) {
            $thrown = $invocation->thrown();
            \assert($thrown instanceof \Throwable);

            return 'threw ' . $thrown::class;
        }

        return 'returned ' . ArgumentFormatter::format($invocation->returned());
    }

    /**
     * Runs a callback in a nested context of its own.
     *
     * On success the callback's expectations are verified; the context is then
     * dropped either way. A failure inside the callback is never replaced by a
     * teardown error — the original is what the reader needs.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public static function scope(callable $callback, bool $strictStubs = false): mixed
    {
        Runtime::pushScope();
        $succeeded = false;

        try {
            /** @var T $result */
            $result = $callback();
            // An explicit flag, not isset($result): a callback that returns
            // null — or nothing at all — is a success like any other.
            $succeeded = true;
        } finally {
            try {
                if ($succeeded) {
                    self::verifyAll($strictStubs);
                }
            } finally {
                Runtime::popScope();
            }
        }

        return $result;
    }

    /**
     * Verifies the current context and clears what has been settled, keeping
     * the understudies themselves — for a long test that runs in phases.
     */
    public static function checkpoint(bool $strictStubs = false): void
    {
        self::verifyAll($strictStubs);

        foreach (Runtime::current()->allStates() as $state) {
            $state->settle();
        }
    }

    /**
     * Drops the current context. Adapters call this after each test,
     * unconditionally; sibling Fiber contexts remain intact.
     */
    public static function reset(): void
    {
        Runtime::reset();
    }

    /**
     * Runs the specification closure with recording on, and catches the signal
     * the called method throws instead of returning.
     */
    private static function record(callable $call): InvocationSignal
    {
        $context = Runtime::current();
        $context->beginRecording();

        try {
            $call();
        } catch (InvocationSignal $signal) {
            return $signal;
        } catch (\Throwable $failure) {
            throw InvalidCallSpecification::closureFailed($failure);
        } finally {
            $context->endRecording();
        }

        throw InvalidCallSpecification::noCallRecorded();
    }

    private static function stateOf(object $double): DoubleState
    {
        $state = Runtime::stateOf($double);

        if ($state === null) {
            throw InvalidCallSpecification::noCallRecorded();
        }

        if (!Runtime::isOwnedByCurrentContext($double)) {
            throw ContextOwnershipViolation::forDouble();
        }

        return $state;
    }
}
