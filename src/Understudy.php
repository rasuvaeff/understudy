<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy;

use Rasuvaeff\Understudy\Bypass\FileWrapper;
use Rasuvaeff\Understudy\Bypass\FinalStripper;
use Rasuvaeff\Understudy\Codegen\DoubleFactory;
use Rasuvaeff\Understudy\Exception\BypassUnavailable;
use Rasuvaeff\Understudy\Exception\ContextOwnershipViolation;
use Rasuvaeff\Understudy\Exception\ForgottenDouble;
use Rasuvaeff\Understudy\Exception\ForwardingTargetMismatch;
use Rasuvaeff\Understudy\Exception\InvalidCallSpecification;
use Rasuvaeff\Understudy\Exception\OriginalCallUnavailable;
use Rasuvaeff\Understudy\Exception\UnsupportedTarget;
use Rasuvaeff\Understudy\Exception\VerificationFailed;
use Rasuvaeff\Understudy\Expectation\ArgumentFormatter;
use Rasuvaeff\Understudy\Expectation\Expectation;
use Rasuvaeff\Understudy\Runtime\ArmedSequence;
use Rasuvaeff\Understudy\Runtime\DoubleState;
use Rasuvaeff\Understudy\Runtime\InvocationSignal;
use Rasuvaeff\Understudy\Runtime\Mode;
use Rasuvaeff\Understudy\Runtime\Runtime;
use Rasuvaeff\Understudy\Runtime\RuntimeContext;
use Rasuvaeff\Understudy\Wiring\Wire;

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
     * An instance may be passed instead of a class name. The double then stands
     * in for that object's class and remembers the object as its forwarding
     * target — but keeps answering with defaults until {@see forwarding()} says
     * otherwise. Wrapping something is not the same as delegating to it, and a
     * double that started running real code the moment it was built would be a
     * surprise, not a shorthand.
     *
     * @template T of object
     *
     * @param class-string<T>|T $target
     * @param class-string      ...$interfaces
     *
     * @return T
     */
    public static function for(object|string $target, string ...$interfaces): object
    {
        $real = \is_object($target) ? $target : null;

        if ($real !== null) {
            self::rejectUnwrappableInstance($real);
        }

        /** @var non-empty-list<class-string> $contracts */
        $contracts = [$real === null ? $target : $real::class, ...array_values($interfaces)];
        $blueprint = DoubleFactory::blueprintFor($contracts);
        // Reflection rather than `new $class()`: the generated class is not
        // statically known, and this is also the path a class double will need,
        // where the target's constructor must be skipped rather than run.
        /** @var T $double */
        $double = DoubleFactory::instantiate($blueprint);

        // The skipped constructor leaves every typed property uninitialized;
        // the ones that can hold an empty scalar or array get one, so that
        // reading them raises nothing. See Codegen\PropertyDefaults for what is
        // deliberately left alone.
        /** @var mixed $value */
        foreach ($blueprint->propertyDefaults as $property => $value) {
            $double->{$property} = $value;
        }

        $state = new DoubleState($blueprint);

        if ($real !== null) {
            // Remembered, not switched on: `forwarding()` is what decides that
            // real code runs.
            $state->setForwardingTarget($real);
        }

        Runtime::adopt($double, $state);

        return $double;
    }

    /**
     * A final class is already loaded by the time an instance of it exists, so
     * the double cannot be a subclass of it and cannot keep the concrete type
     * the caller is holding.
     */
    private static function rejectUnwrappableInstance(object $real): void
    {
        if (!(new \ReflectionClass($real))->isFinal()) {
            return;
        }

        throw UnsupportedTarget::notDoublable(
            $real::class,
            'a final class cannot be extended, and an instance of one is already the class it is. Pass an '
            . 'interface it implements to Understudy::for() and give the instance to '
            . 'Understudy::forwarding($double, $real).',
        );
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
        // Every context the test put understudies in, not only the one this
        // call happens to stand in: a body run in a Fiber owns a context of
        // its own, and skipping it let an unmet `expect()` pass unnoticed.
        self::report(Runtime::liveContexts(), $strictStubs);
    }

    /**
     * Raises one report over the given contexts, or returns quietly.
     *
     * @param list<RuntimeContext> $contexts
     */
    private static function report(array $contexts, bool $strictStubs): void
    {
        // One alias table for the whole report, not one per failure:
        // `VerificationFailed` joins the summaries into a single message, and
        // an object numbered in the first summary has to keep that number in
        // the third — otherwise two `Book#1` on one screen mean two objects.
        $failures = ArgumentFormatter::scope(static function () use ($contexts, $strictStubs): array {
            $failures = [];

            foreach ($contexts as $context) {
                $failures = [...$failures, ...self::failuresIn($context, $strictStubs)];
            }

            return $failures;
        });

        if ($failures !== []) {
            throw VerificationFailed::of($failures);
        }
    }

    /**
     * Everything one context has to answer for: its expectations, its
     * ordering and its armed protocol.
     *
     * @return list<VerificationFailure>
     */
    private static function failuresIn(RuntimeContext $context, bool $strictStubs): array
    {
        $contextFailures = [];

        foreach ($context->allStates() as $state) {
            foreach ($state->expectations() as $expectation) {
                $failure = self::checkExpectation($state, $expectation, $strictStubs);

                if ($failure !== null) {
                    $contextFailures[] = $failure;
                }
            }
        }

        $failures = array_reverse($contextFailures);

        // Ordering is read from the sequence counter, which is per context.
        // Comparing across contexts would compare two unrelated countings, so
        // each context answers for its own order.
        $outOfOrder = self::checkOrdering(context: $context);

        if ($outOfOrder !== null) {
            $failures[] = $outOfOrder;
        }

        // An armed protocol guards the order *and* claims the calls. Without
        // this half, arming one and never exercising it — the subject stopped
        // after step two, or a `catch` inside it swallowed the refusal —
        // would pass in silence.
        $unfinished = self::checkArmedSequence($context);

        if ($unfinished !== null) {
            $failures[] = $unfinished;
        }

        return $failures;
    }

    /**
     * Ordered expectations must be satisfied in the order they were declared,
     * relative to each other. Calls in between are none of their business —
     * `verifySequence()` is the tool for an exact protocol.
     */
    private static function checkOrdering(?DoubleState $only = null, ?RuntimeContext $context = null): ?VerificationFailure
    {
        /** @var list<array{DoubleState, Expectation}> $ordered */
        $ordered = [];

        foreach (($context ?? Runtime::current())->allStates() as $state) {
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

        if ($sequence === null || $sequence->isComplete()) {
            return null;
        }

        $pending = $sequence->pending();
        \assert($pending !== null);

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

    private static function checkExpectation(DoubleState $state, Expectation $expectation, bool $strictStubs): ?VerificationFailure
    {
        $cardinality = $expectation->cardinality();
        $count = $expectation->matchCount();

        if ($cardinality === null) {
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
        $sameMethod = array_values(array_filter(
            $state->callLog(),
            static fn(Invocation $invocation): bool => $invocation->method === $expectation->method,
        ));

        return ArgumentFormatter::scope(static fn(): VerificationFailure => new VerificationFailure(
            kind: FailureKind::UnmetExpectation,
            summary: FailureReport::render(
                label: $state->label(),
                expectation: $expectation->describe(),
                expectedLow: $cardinality->minimum,
                expectedHigh: $cardinality->maximum,
                actual: $count,
                callLog: $state->callLog(),
                method: $expectation->method,
                expectedArgs: $expectation->args,
            ),
            double: $state->label(),
            expectation: $expectation->describe(),
            expectedMinimum: $cardinality->minimum,
            expectedMaximum: $cardinality->maximum,
            actualCount: $count,
            observedCalls: $sameMethod,
        ));
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

            // The Mockito-style read: capture at verification time, from the
            // calls this verification just claimed. Only on success — a
            // failed verify() throws, and values captured on the way to a
            // failure would be read by nobody.
            if ($probe->hasCaptors()) {
                $context = Runtime::current();

                foreach ($matched as $invocation) {
                    foreach ($probe->captureFrom($invocation->args) as $captor) {
                        $context->rememberCaptor($captor);
                    }
                }
            }

            return;
        }

        $sameMethod = array_values(array_filter(
            $state->callLog(),
            static fn(Invocation $invocation): bool => $invocation->method === $signal->method,
        ));

        throw VerificationFailed::of([
            ArgumentFormatter::scope(static fn(): VerificationFailure => new VerificationFailure(
                kind: FailureKind::UnmetExpectation,
                summary: FailureReport::render(
                    label: $state->label(),
                    expectation: $probe->describe(),
                    expectedLow: $low,
                    expectedHigh: $high,
                    actual: $matches,
                    callLog: $state->callLog(),
                    method: $signal->method,
                    expectedArgs: $signal->args,
                ),
                double: $state->label(),
                expectation: $probe->describe(),
                expectedMinimum: $low,
                expectedMaximum: $high,
                actualCount: $matches,
                observedCalls: $sameMethod,
            )),
        ]);
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
     * The most recent recorded call matching the specification, or null when
     * there was none.
     *
     * The null-safe replacement for reading `count($calls) - 1` out of
     * {@see calls()}: an empty log has no last element, and Psalm cannot
     * prove otherwise, so the index arithmetic reports `int<-1, max>` before
     * the test even runs.
     *
     * @param callable(): mixed $call
     */
    public static function lastCall(callable $call): ?Invocation
    {
        $signal = self::record($call);
        $probe = new Expectation($signal->method, $signal->args);
        $last = null;

        foreach (self::stateOf($signal->double)->callLog() as $invocation) {
            if ($probe->matches($invocation->method, $invocation->args)) {
                $last = $invocation;
            }
        }

        return $last;
    }

    /**
     * Makes an understudy fail on any call no expectation matched.
     */
    public static function strict(object $double): void
    {
        self::stateOf($double)->setMode(Mode::Strict);
    }

    /**
     * Stops this understudy's call log from retaining returned values.
     *
     * The log holds every invocation together with its outcome until
     * `reset()` — and with the runner adapters that is *after* the test's own
     * teardown. For plain data that is only memory; for a value that owns an
     * OS resource — a stream, a connection, a lock — the resource is still
     * held while teardown runs. A lean understudy keeps the invocation
     * (method, arguments, sequence), so matching, `verify()`, `transcript()`
     * and `nothingElse()` work unchanged, but the returned value is not kept:
     * `Invocation::returned()` raises `OutcomeUnavailable`, the way it already
     * does for a call that threw. It also caps the per-call memory growth of a
     * hot loop through the double.
     *
     * One-way for the double's lifetime. `Understudy::scope()` is the other
     * remedy: it drops the whole context — outcomes included — before the
     * lifecycle teardown runs.
     */
    public static function lean(object $double): void
    {
        self::stateOf($double)->makeLean();
    }

    /**
     * Delegates unmatched calls to a real instance, recording each one.
     *
     * With `$real`, the double starts standing in front of that object; without
     * it, the mode is turned on for a double built from an instance by
     * {@see for()}. Splitting the two is deliberate: `for($real)` gives you a
     * double that remembers where it came from, and until you say so it still
     * answers with defaults rather than running real code.
     *
     * Only the call at the boundary is recorded. A real method that calls
     * another method on itself does so inside the real object; understudy
     * proxies an object, it does not instrument one.
     */
    public static function forwarding(object $double, ?object $real = null): void
    {
        $state = self::stateOf($double);

        if ($real !== null) {
            // A double delegating to a double — itself included — sends every
            // call back into the dispatcher it came from.
            if (Runtime::ownerOf($real) !== null) {
                throw ForwardingTargetMismatch::understudyTarget($state->label());
            }

            foreach ($state->blueprint->contracts as $contract) {
                if (!$real instanceof $contract) {
                    throw ForwardingTargetMismatch::missingContract($state->label(), $contract, $real::class);
                }
            }

            $state->setForwardingTarget($real);
        } elseif ($state->forwardingTarget() === null) {
            throw OriginalCallUnavailable::forMode($state->label());
        }

        $state->setMode(Mode::Forwarding);
    }

    /**
     * Builds a double of one contract that delegates every unmatched call to
     * the given instance, and hands the double back — {@see for()} plus
     * {@see forwarding()} in one expression:
     *
     * ```php
     * $store = Understudy::delegate(StoreInterface::class, $this->store);
     * when(fn () => $store->delete(Arg::any()))->throws(new StoreException('unreachable'));
     * ```
     *
     * A separate verb rather than an overload of `forwarding()`: that method
     * answers `void` for a double built earlier, and a return value that
     * appears only for one shape of the first argument is the kind of magic a
     * reader should not have to know about.
     *
     * The target is validated the way `forwarding()` validates it: it must
     * satisfy the contract, and an understudy is refused — delegating to one
     * sends every call back into the dispatcher it came from.
     *
     * @template T of object
     *
     * @param class-string<T> $contract
     * @param T               $real
     *
     * @return T
     */
    public static function delegate(string $contract, object $real): object
    {
        $double = self::for($contract);
        self::forwarding($double, $real);

        return $double;
    }

    /**
     * Builds a real subject with an understudy for every constructor
     * dependency, and hands back both.
     *
     * ```php
     * ['sut' => $service, 'doubles' => $d] = Understudy::wire(CatalogService::class);
     * ```
     *
     * It reads the constructor and nothing else: no container, no property
     * injection, no setters. A unit test cares about the collaborators the
     * class itself asks for, and anything else would be guessing about a design
     * the test cannot see.
     *
     * `overrides` replaces one dependency by parameter name, with a real
     * instance or a double you built yourself; those are yours already, so they
     * do not appear in `doubles`. Every refusal happens before the constructor
     * runs — a half-built subject would show the test a TypeError from inside
     * code it did not write.
     *
     * @param class-string         $sut
     * @param array<string, mixed> $overrides
     *
     * @return array{sut: object, doubles: array<string, object>}
     */
    public static function wire(string $sut, array $overrides = []): array
    {
        return Wire::build($sut, $overrides);
    }

    /**
     * Lifts `final` off a class so it can be doubled, before the class is
     * loaded.
     *
     * ```php
     * Understudy::bypassFinals(FinalGate::class);  // one class
     * Understudy::bypassFinals();                  // every class, from bootstrap
     * ```
     *
     * Opt-in, and deliberately so. Doubling a final class means telling PHP
     * something untrue about the code under test for the rest of the process,
     * and the technique has limits worth meeting knowingly: it works only for
     * classes not yet loaded, it needs a `file://` wrapper nothing else has
     * claimed, and it cannot reach a class inside a PHAR or one that was
     * preloaded.
     *
     * `final` on *methods* is never touched. Such a method stays unoverridable
     * after the class opens up, and a double that let one through would run the
     * target's real code — so a class carrying one is still refused.
     *
     * Preferred alternatives, in order: double an interface the class
     * implements; for a value object, build a real one; introduce an interface.
     * Bypass is for the case where none of those is available — somebody else's
     * final class standing between a test and the code under test.
     *
     * @param class-string|null $class null lifts it for every class the process
     *                                 loads from here on, which is what a
     *                                 bootstrap wants
     *
     * @throws BypassUnavailable when the class is already loaded, is not a
     *                           class, or `file://` belongs to somebody else
     */
    public static function bypassFinals(?string $class = null): void
    {
        if (!FileWrapper::isInstalled() && self::foreignSourceTransform()) {
            throw BypassUnavailable::foreignWrapper('the source it read back was not the source on disk');
        }

        if ($class === null) {
            FileWrapper::install(null);

            return;
        }

        // Only a type already in memory can be classified: asking the
        // autoloader would load the very class the caller wants opened, and a
        // class is read from disk once. An unloaded enum or interface therefore
        // passes here and is refused later, by `for()`, which is the moment it
        // matters.
        if (enum_exists($class, autoload: false)) {
            throw BypassUnavailable::notAClass($class, 'an enum');
        }

        if (interface_exists($class, autoload: false)) {
            throw BypassUnavailable::notAClass($class, 'an interface');
        }

        if (class_exists($class, autoload: false)) {
            throw BypassUnavailable::alreadyLoaded($class);
        }

        $position = strrpos($class, '\\');

        FileWrapper::install([[
            'namespace' => $position === false ? '' : substr($class, 0, $position),
            'class' => $position === false ? $class : substr($class, $position + 1),
        ]]);
    }

    /**
     * Whether something already transforms PHP source read through `file://`.
     *
     * PHP exposes no owner for a protocol — `stream_get_wrappers()` lists
     * `file` whoever handles it, and every register/restore call answers `true`
     * either way — so the question is asked of behaviour instead. The stripper's
     * own file declares `final class FinalStripper`, and a wrapper that strips
     * `final` from class declarations, which is precisely the incompatible one,
     * will have removed it by the time the bytes arrive here.
     *
     * What it catches: another source transformer. What it does not: a wrapper
     * that leaves PHP source alone, which by definition composes with this one
     * anyway. Not a guarantee dressed up as one.
     */
    private static function foreignSourceTransform(): bool
    {
        $file = (new \ReflectionClass(FinalStripper::class))->getFileName();

        if ($file === false) {
            return false;
        }

        $source = @file_get_contents($file);

        if ($source === false) {
            // Nothing readable to compare against; refusing here would fail a
            // bypass for a reason that has nothing to do with wrappers.
            return false;
        }

        return !str_contains($source, 'final class FinalStripper');
    }

    /**
     * Registers what a loose double should hand back for one contract.
     *
     * A nested double of `LoggerInterface` answers everything with a default
     * and tells the test nothing; a `NullLogger` is what it wanted. Resolution
     * is by distance in the type graph — exact match first, then the nearest
     * registered ancestor — so the answer does not depend on the order the
     * factories were registered in.
     *
     * The registry belongs to the current context: sibling Fibers do not see
     * each other's, and `reset()` drops them with the test.
     *
     * @param class-string    $contract
     * @param callable(): mixed $factory
     */
    public static function defaults(string $contract, callable $factory): void
    {
        Runtime::current()->defaultFactories()->register($contract, $factory(...));
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

        throw VerificationFailed::of([
            ArgumentFormatter::scope(static fn(): VerificationFailure => new VerificationFailure(
                kind: FailureKind::UnusedDouble,
                summary: sprintf(
                    "Understudy `%s` was expected to be unused, but received %d call(s):\n%s",
                    $state->label(),
                    count($log),
                    FailureReport::renderCallLog($log),
                ),
                double: $state->label(),
                expectedMinimum: 0,
                expectedMaximum: 0,
                actualCount: count($log),
                observedCalls: $log,
            )),
        ]);
    }

    /**
     * Retires an understudy on purpose.
     *
     * For the double a test built and then replaced — `$this->generator =
     * $this->fixedGenerator('other')` leaves the first one behind, still
     * holding its stubs. Under `verifyAll(strictStubs: true)` that stub is a
     * failure about a double the test no longer uses; `forget()` says it was
     * retired, so verification and reset stop seeing it. Calling anything on
     * the object afterwards fails with `ForgottenDouble`.
     *
     * One-way, like every other form of forgetting here: a double belongs to
     * exactly one context for its whole life.
     */
    public static function forget(object $double): void
    {
        if (Runtime::ownerOf($double) === null) {
            throw InvalidCallSpecification::notADouble();
        }

        if (!Runtime::isOwnedByCurrentContext($double)) {
            throw ContextOwnershipViolation::forDouble();
        }

        Runtime::forget($double);
    }

    /**
     * Asserts that every call these understudies received has been accounted
     * for: matched by an `expect()`, or claimed by a successful `verify()`.
     *
     * Accepts any number of doubles, so one line can close out a test that
     * used several: every double named is checked, and a failure reports
     * each offender rather than stopping at the first.
     */
    public static function nothingElse(object $double, object ...$more): void
    {
        // One table across every double named here: the report is one message,
        // and the same object handed to two of them is one object.
        $failures = ArgumentFormatter::scope(static function () use ($double, $more): array {
            $failures = [];

            foreach ([$double, ...array_values($more)] as $one) {
                $state = self::stateOf($one);

                $unaccounted = array_values(array_filter(
                    $state->callLog(),
                    static fn(Invocation $invocation): bool => !$invocation->isAccounted(),
                ));

                if ($unaccounted !== []) {
                    $failures[] = new VerificationFailure(
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
            }

            return $failures;
        });

        if ($failures !== []) {
            throw VerificationFailed::of($failures);
        }
    }

    /**
     * Asserts this understudy's expectations are satisfied and that nothing
     * else happened to it — the two halves of "I have described everything".
     */
    public static function allVerified(object $double): void
    {
        $state = self::stateOf($double);

        // Both halves land in one message, so both are numbered against one
        // alias table.
        $failures = ArgumentFormatter::scope(static function () use ($state): array {
            $failures = [];

            foreach ($state->expectations() as $expectation) {
                $failure = self::checkExpectation($state, $expectation, strictStubs: false);

                if ($failure !== null) {
                    $failures[] = $failure;
                }
            }

            $outOfOrder = self::checkOrdering($state);

            if ($outOfOrder !== null) {
                $failures[] = $outOfOrder;
            }

            $unaccounted = array_values(array_filter(
                $state->callLog(),
                static fn(Invocation $invocation): bool => !$invocation->isAccounted(),
            ));

            if ($unaccounted !== []) {
                $failures[] = new VerificationFailure(
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

            return $failures;
        });

        if ($failures !== []) {
            throw VerificationFailed::of($failures);
        }
    }

    /**
     * Arms a protocol before the code under test runs, so that a call breaking
     * the order fails at that call — with the subject's own frame on top of the
     * stack — instead of in teardown.
     *
     * ```php
     * Understudy::expectSequence(
     *     fn () => $repo->begin(),
     *     fn () => $repo->save($book),
     *     fn () => $repo->commit(),
     * );
     *
     * $service->handle($command);   // fails here, on the call that broke it
     * ```
     *
     * Totality is scoped to the doubles the protocol names: a call on one of
     * them is either the step due or something the test configured, and a
     * double the protocol never names is invisible to it. That is why a query
     * a subject makes between two steps has to be stubbed — without a `when()`
     * the protocol cannot tell "not part of this" from "you got the order
     * wrong", and guessing would put the failure back in teardown, which is
     * what arming exists to avoid.
     *
     * Each step is due exactly once, in order. `expect(...)->ordered()` is the
     * tool for a relative order that tolerates repeats and calls in between;
     * `verifySequence()` is the same total protocol checked afterwards.
     *
     * An armed protocol is also a claim: `verifyAll()` reports the steps the
     * subject never reached, so arming one and never exercising it fails.
     *
     * @param callable(): mixed ...$calls
     *
     * @api
     */
    public static function expectSequence(callable ...$calls): void
    {
        if ($calls === []) {
            throw InvalidCallSpecification::emptySequence();
        }

        $context = Runtime::current();
        $armed = $context->armed;

        // Only a concurrent one is refused. A protocol that ran to completion
        // has nothing left to disagree about, and a two-phase test must be
        // able to describe its second phase.
        if ($armed !== null && !$armed->isComplete()) {
            throw InvalidCallSpecification::protocolAlreadyArmed($armed->position(), $armed->length());
        }

        $steps = [];

        foreach ($calls as $call) {
            $signal = self::record($call);
            self::stateOf($signal->double);
            $steps[] = [$signal->double, new Expectation($signal->method, $signal->args)];
        }

        $context->arm(new ArmedSequence($steps));
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
            throw VerificationFailed::of([$mismatch]);
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

        return ArgumentFormatter::scope(static function () use ($state, $log): string {
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
        });
    }

    /**
     * @param list<array{object, Expectation}> $probes
     * @param list<Invocation>                 $log
     */
    private static function firstSequenceMismatch(array $probes, array $log): ?VerificationFailure
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
    private static function describeOutcome(Invocation $invocation): string
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

    /**
     * Runs a callback in a nested context of its own.
     *
     * On success the callback's expectations are verified; the context is then
     * dropped either way. A failure inside the callback is never replaced by a
     * teardown error — the original is what the reader needs.
     *
     * Verified is the context this call opened, and only it: the enclosing
     * context is still running and its claims are none of a nested scope's
     * business. A scope is a local lifetime — the caller reaches for it to end
     * a few doubles early, often while its own expectations are deliberately
     * unfinished — so answering for the whole test here would fail a
     * self-contained scope for something the test has not got to yet. The
     * test as a whole is answered for by `verifyAll()`, `checkpoint()` and the
     * runner adapter's teardown, which are the calls that mean the test is
     * over. A Fiber started inside the callback keeps a context of its own
     * that outlives the scope, so it stays for those to check.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public static function scope(callable $callback, bool $strictStubs = false): mixed
    {
        $context = Runtime::pushScope();
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
                    self::report([$context], $strictStubs);
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

        foreach (Runtime::liveContexts() as $context) {
            foreach ($context->allStates() as $state) {
                $state->settle();
            }

            // The protocol belongs to the phase that declared it, and
            // `verifyAll()` above has already answered for it: an unfinished
            // one never reaches this line. Nothing special is decided here —
            // a checkpoint verifies and then clears, as it does for everything
            // else in the phase.
            $context->disarm();
        }
    }

    /**
     * Drops every context this test put understudies in — the caller's and
     * any a Fiber owns. Adapters call it after each test, unconditionally.
     *
     * Wider than isolation on purpose. A Fiber keeps its own recording phase,
     * call log and sequence counter so that concurrent bodies never collide;
     * but teardown is about the test, and a context the adapter cannot see is
     * a context whose doubles answer the next one.
     */
    public static function reset(): void
    {
        Runtime::reset();
    }

    /**
     * Whether the test holds no understudies at all, in any context it used.
     *
     * Runner adapters use it as an integration guard: a context that is not
     * idle by the time the next test begins means some earlier test's cleanup
     * never ran, and its doubles are about to leak into this one.
     */
    public static function idle(): bool
    {
        foreach (Runtime::liveContexts() as $context) {
            if ($context->allStates() !== []) {
                return false;
            }
        }

        return true;
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
            // A specification that ended with Arg::rest() physically passed
            // fewer arguments than the method declares; the generated
            // parameters answered with their sentinel defaults, and those are
            // stripped — or the omission is refused — before anything reads
            // the arguments as a specification.
            return $signal->withoutAbsentArguments();
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
            if (Runtime::isRetiredOnPurpose($double)) {
                throw ForgottenDouble::retired();
            }

            throw InvalidCallSpecification::noCallRecorded();
        }

        if (!Runtime::isOwnedByCurrentContext($double)) {
            throw ContextOwnershipViolation::forDouble();
        }

        return $state;
    }
}
