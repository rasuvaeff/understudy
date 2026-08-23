<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy;

use Rasuvaeff\Understudy\Bypass\FileWrapper;
use Rasuvaeff\Understudy\Bypass\FinalStripper;
use Rasuvaeff\Understudy\Codegen\DoubleFactory;
use Rasuvaeff\Understudy\Exception\BypassUnavailable;
use Rasuvaeff\Understudy\Exception\ContextOwnershipViolation;
use Rasuvaeff\Understudy\Exception\ForwardingTargetMismatch;
use Rasuvaeff\Understudy\Exception\InvalidCallSpecification;
use Rasuvaeff\Understudy\Exception\OriginalCallUnavailable;
use Rasuvaeff\Understudy\Exception\UnsupportedTarget;
use Rasuvaeff\Understudy\Exception\VerificationFailed;
use Rasuvaeff\Understudy\Expectation\ArgumentFormatter;
use Rasuvaeff\Understudy\Expectation\Expectation;
use Rasuvaeff\Understudy\Runtime\DoubleState;
use Rasuvaeff\Understudy\Runtime\InvocationSignal;
use Rasuvaeff\Understudy\Runtime\Mode;
use Rasuvaeff\Understudy\Runtime\Runtime;
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
        $double = (new \ReflectionClass($blueprint->generatedClass))->newInstanceWithoutConstructor();

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
