<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Runtime;

use Rasuvaeff\Understudy\Codegen\DoubleFactory;
use Rasuvaeff\Understudy\Defaults\TypeDefaultResolver;
use Rasuvaeff\Understudy\Exception\ForgottenDouble;
use Rasuvaeff\Understudy\Exception\InvalidCallSpecification;
use Rasuvaeff\Understudy\Exception\MatcherLeaked;
use Rasuvaeff\Understudy\Exception\NeverMethodCalled;
use Rasuvaeff\Understudy\Exception\OriginalCallUnavailable;
use Rasuvaeff\Understudy\Exception\OriginalReturnTypeViolation;
use Rasuvaeff\Understudy\Exception\StrictModeViolation;
use Rasuvaeff\Understudy\Exception\VerificationFailed;
use Rasuvaeff\Understudy\Expectation\ArgumentFormatter;
use Rasuvaeff\Understudy\FailureKind;
use Rasuvaeff\Understudy\FailureReport;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Matcher\ArgumentMatcher;
use Rasuvaeff\Understudy\VerificationFailure;

/**
 * The registry generated doubles call into, and the owner of every context.
 *
 * @internal
 */
final class Runtime
{
    /** How deep an argument snapshot follows nested arrays. */
    private const int SNAPSHOT_DEPTH = 8;

    /**
     * A stack, not a single context: `scope()` nests one inside another, and
     * the outer one must come back intact when the inner ends.
     *
     * @var list<RuntimeContext>
     */
    private static array $main = [];

    /** @var \WeakMap<\Fiber, list<RuntimeContext>>|null */
    private static ?\WeakMap $fibers = null;

    /**
     * Keyed by the double object, never by `spl_object_id()`, which PHP reuses
     * after collection. Routes a call made inside a Fiber back to the context
     * that created the double.
     *
     * @var \WeakMap<object, RuntimeContext>|null
     */
    private static ?\WeakMap $owners = null;

    /**
     * @var \WeakMap<object, true>|null
     */
    private static ?\WeakMap $forgotten = null;

    /**
     * Doubles retired on purpose through `Understudy::forget()`. The
     * distinction is for the failure message only: both a reset and a
     * deliberate retirement make the double unusable, but telling a reader
     * to look for a reset() they never wrote is worse than the plain truth.
     *
     * @var \WeakMap<object, true>|null
     */
    private static ?\WeakMap $retiredOnPurpose = null;

    /**
     * Every context that holds understudies, current or not.
     *
     * Isolation and accounting are different questions, and conflating them
     * cost a silent hole. A Fiber gets its own context so that a recording
     * phase, a call log and a sequence counter are never shared with a
     * sibling — that stays. But `verifyAll()`, `reset()`, `idle()` and
     * `checkpoint()` are asked by a runner adapter from the main flow, about
     * the test as a whole, and a context they cannot see is a context whose
     * expectations are never checked: a test body run in a Fiber left an
     * unmet `expect()` and the suite stayed green.
     *
     * Keyed by object id and holding a strong reference, so a Fiber's context
     * outlives the Fiber long enough to be verified. `reset()` empties it.
     *
     * @var array<int, RuntimeContext>
     */
    private static array $live = [];

    public static function current(): RuntimeContext
    {
        $stack = self::stack();

        if ($stack === []) {
            $context = self::freshContext();
            self::push($context);

            return $context;
        }

        return $stack[count($stack) - 1];
    }

    public static function currentIfAny(): ?RuntimeContext
    {
        $stack = self::stack();

        return $stack === [] ? null : $stack[count($stack) - 1];
    }

    /**
     * Opens a nested context, so that everything created inside it is dropped
     * when it closes and the enclosing context resumes untouched.
     */
    public static function pushScope(): RuntimeContext
    {
        $context = self::freshContext();
        self::push($context);

        return $context;
    }

    public static function popScope(): void
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber === null) {
            $context = array_pop(self::$main);

            if ($context instanceof RuntimeContext) {
                self::retire($context);
            }

            return;
        }

        $fibers = self::fibers();
        /** @var list<RuntimeContext> $stack */
        $stack = $fibers->offsetExists($fiber) ? $fibers->offsetGet($fiber) : [];
        $context = array_pop($stack);

        if ($context instanceof RuntimeContext) {
            self::retire($context);
        }

        $fibers->offsetSet($fiber, $stack);
    }

    /**
     * @return list<RuntimeContext>
     */
    private static function stack(): array
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber === null) {
            return self::$main;
        }

        $fibers = self::fibers();

        if (!$fibers->offsetExists($fiber)) {
            return [];
        }

        // WeakMap::offsetGet() is typed as nullable regardless of the value
        // type, and offsetExists() above has already ruled that out.
        /** @var list<RuntimeContext> $stack */
        $stack = $fibers->offsetGet($fiber);

        return $stack;
    }

    private static function push(RuntimeContext $context): void
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber === null) {
            self::$main[] = $context;

            return;
        }

        $stack = self::stack();
        $stack[] = $context;
        self::fibers()->offsetSet($fiber, $stack);
    }

    public static function adopt(object $double, DoubleState $state): void
    {
        $context = self::current();
        $context->register($double, $state);
        self::owners()->offsetSet($double, $context);
    }

    /**
     * Records a context as holding understudies, so accounting can reach it
     * from wherever the adapter stands.
     */
    /**
     * Called where a context comes into being, not once per double adopted
     * into it: the context is the same for every double a test builds, so
     * doing it in `adopt()` rewrote the same key on the hottest creation path
     * there is. A context that ends up holding nothing is harmless here —
     * accounting walks its states, and it has none.
     */
    private static function remember(RuntimeContext $context): void
    {
        self::$live[spl_object_id($context)] = $context;
    }

    private static function unremember(RuntimeContext $context): void
    {
        unset(self::$live[spl_object_id($context)]);
    }

    /**
     * The current context first, then every other one still holding
     * understudies — the whole test, in the order a report should read.
     *
     * @return list<RuntimeContext>
     */
    public static function liveContexts(): array
    {
        $current = self::currentIfAny();
        $contexts = $current === null ? [] : [$current];

        foreach (self::$live as $context) {
            if ($context !== $current) {
                $contexts[] = $context;
            }
        }

        return $contexts;
    }

    /**
     * Registers a freshly cloned double, called from the generated `__clone()`.
     *
     * The copy gets a state of its own built from the same blueprint: no
     * expectations, no call log, no label, no mode. Copying any of it would let
     * a double the code under test produced satisfy a verification written
     * against the one the test set up.
     *
     * The copy belongs to the context that performed the `clone`, which is the
     * same rule `Understudy::for()` follows: whoever creates a double owns it.
     * That is a decision, not an oversight — `__clone()` runs on the copy and
     * PHP hands it no reference to the original, so the owner of the original
     * cannot be recovered here at all. A `clone` inside a Fiber therefore
     * produces a copy that Fiber owns, and configuring or verifying it from
     * outside raises `ContextOwnershipViolation` like any other cross-context
     * access. Clone in the scope that will use it.
     */
    public static function adoptClone(object $clone): void
    {
        $blueprint = DoubleFactory::blueprintOfGenerated($clone::class);

        if ($blueprint === null) {
            // Only a generated class reaches this method, and every one of them
            // is registered when it is compiled.
            return;
        }

        $context = self::current();
        $context->register($clone, new DoubleState($blueprint));

        self::owners()->offsetSet($clone, $context);
    }

    /**
     * Builds a double and registers it in the given context rather than the
     * current one.
     *
     * Used for the depth-1 double a loose default hands back: the owner of the
     * double being answered is the owner of the nested one too. Going through
     * `Understudy::for()` would adopt it into whichever Fiber happened to be
     * running, and the test that owns the outer double could then neither
     * configure nor verify what it got back.
     *
     * @param class-string $contract
     */
    public static function adoptInto(RuntimeContext $owner, string $contract): object
    {
        return self::adoptContractsInto($owner, [$contract]);
    }

    /**
     * Builds a loose default for an intersection return type. The generated
     * class must implement every atom of `A&B`, not a class literally named
     * with the ampersand.
     *
     * @param non-empty-list<class-string> $contracts
     */
    public static function adoptContractsInto(RuntimeContext $owner, array $contracts): object
    {
        $blueprint = DoubleFactory::blueprintFor($contracts);
        $double = (new \ReflectionClass($blueprint->generatedClass))->newInstanceWithoutConstructor();

        /** @var mixed $value */
        foreach ($blueprint->propertyDefaults as $property => $value) {
            $double->{$property} = $value;
        }

        $owner->register($double, new DoubleState($blueprint, nested: true));
        self::owners()->offsetSet($double, $owner);

        return $double;
    }

    public static function ownerOf(object $double): ?RuntimeContext
    {
        $owners = self::owners();

        return $owners->offsetExists($double) ? $owners->offsetGet($double) : null;
    }

    /**
     * Whether a double's context was torn down under it.
     *
     * A one-way latch on purpose: a double belongs to exactly one context for
     * its whole life, and `for()` hands back a new object every time, so
     * nothing that was forgotten can become known again.
     */
    public static function isForgotten(object $double): bool
    {
        return self::$forgotten?->offsetExists($double) ?? false;
    }

    /**
     * Whether a double was retired on purpose through `Understudy::forget()`.
     */
    public static function isRetiredOnPurpose(object $double): bool
    {
        return self::$retiredOnPurpose?->offsetExists($double) ?? false;
    }

    /**
     * Retires a double on purpose: its owner's state is dropped, so
     * verification, `nothingElse()` and reset stop seeing it — a replacement
     * double must not inherit its stubs into a `strictStubs` verdict. A call
     * on the object afterwards fails with `ForgottenDouble`, the same guard a
     * scope close leaves behind.
     */
    public static function forget(object $double): void
    {
        $owner = self::ownerOf($double);

        if ($owner === null || $owner->stateOf($double) === null) {
            throw InvalidCallSpecification::noCallRecorded();
        }

        $owner->forget($double);
        self::owners()->offsetUnset($double);
        self::forgotten()->offsetSet($double, value: true);

        if (self::$retiredOnPurpose === null) {
            /** @var \WeakMap<object, true> $retired */
            $retired = new \WeakMap();
            self::$retiredOnPurpose = $retired;
        }

        self::$retiredOnPurpose->offsetSet($double, value: true);
    }

    /**
     * @return \WeakMap<\Fiber, list<RuntimeContext>>
     */
    private static function fibers(): \WeakMap
    {
        if (self::$fibers === null) {
            /** @var \WeakMap<\Fiber, list<RuntimeContext>> $fibers */
            $fibers = new \WeakMap();
            self::$fibers = $fibers;
        }

        return self::$fibers;
    }

    /**
     * @return \WeakMap<object, RuntimeContext>
     */
    private static function owners(): \WeakMap
    {
        if (self::$owners === null) {
            /** @var \WeakMap<object, RuntimeContext> $owners */
            $owners = new \WeakMap();
            self::$owners = $owners;
        }

        return self::$owners;
    }

    /**
     * @return \WeakMap<object, true>
     */
    private static function forgotten(): \WeakMap
    {
        if (self::$forgotten === null) {
            /** @var \WeakMap<object, true> $forgotten */
            $forgotten = new \WeakMap();
            self::$forgotten = $forgotten;
        }

        return self::$forgotten;
    }

    public static function stateOf(object $double): ?DoubleState
    {
        return self::ownerOf($double)?->stateOf($double);
    }

    public static function isOwnedByCurrentContext(object $double): bool
    {
        $owner = self::ownerOf($double);

        return $owner !== null && $owner === self::current();
    }

    /**
     * Entry point of every generated method.
     *
     * @param non-empty-string $method
     * @param list<mixed>      $args
     */
    public static function dispatch(object $double, string $method, array $args): mixed
    {
        // Recording is a property of the caller, not of the double: when()
        // opens the phase in whichever context it runs in, and a double
        // created elsewhere must still signal instead of being treated as a
        // real call.
        $current = self::current();

        if ($current->isRecording()) {
            throw new InvocationSignal($double, $method, $args);
        }

        self::rejectLeakedMatchers($method, $args);

        // Most calls stay in the context that created the double. Avoid the
        // WeakMap owner lookup on that hot path; retain it for cross-Fiber
        // calls where the current context does not know the object.
        $context = $current;
        $state = $current->stateOf($double);

        if ($state === null) {
            $context = self::ownerOf($double) ?? $current;
            $state = $context->stateOf($double);
        }

        if ($state === null) {
            // Returning null here would violate the declared return type of
            // any non-nullable method, so say what actually happened. The
            // message differs for a double retired through `Understudy::forget()`:
            // sending the reader looking for a stray reset() they never wrote
            // is worse than the plain truth.
            if (self::isRetiredOnPurpose($double)) {
                throw ForgottenDouble::onPurpose($method);
            }

            throw ForgottenDouble::afterReset($method);
        }

        // A by-reference argument is live: whatever answers the call can write
        // through it, and the log would then show a value the caller never
        // passed. Both sides are kept, and only for the methods that can move —
        // snapshotting every call would cost the whole suite for a rare case.
        $tracksReferences = $state->blueprint->method($method)?->hasReferenceParameters ?? false;

        $invocation = new Invocation(
            method: $method,
            args: $tracksReferences ? self::detached($args) : $args,
            sequence: $context->nextSequence(),
            double: $double,
            liveArgs: $args,
        );
        $state->record($invocation);

        try {
            /** @var mixed $value */
            $value = self::answer($state, $double, $method, $invocation, $context, $args);
        } catch (\Throwable $thrown) {
            if ($tracksReferences) {
                $invocation->recordFinalArguments(self::detached($args));
            }

            $invocation->recordThrown($thrown);

            throw $thrown;
        }

        if ($tracksReferences) {
            $invocation->recordFinalArguments(self::detached($args));
        }

        // A lean double keeps the invocation but not the value it answered:
        // the log would otherwise hold a reference until reset(), which with
        // the runner adapters is *after* the test's own teardown — a returned
        // stream is then still open while teardown removes the directory it
        // sits in (found on Windows, where an open file cannot be unlinked).
        if ($state->isLean()) {
            $invocation->recordDiscardedReturn();
        } else {
            $invocation->recordReturned($value);
        }

        return $value;
    }

    /**
     * @param non-empty-string $method
     * @param list<mixed>      $args live arguments, references included
     */
    private static function answer(
        DoubleState $state,
        object $double,
        string $method,
        Invocation $invocation,
        RuntimeContext $context,
        array $args,
    ): mixed {
        $signature = $state->blueprint->method($method);
        $matched = false;

        // Before anything answers the call. A call that is a step of the armed
        // protocol arriving out of turn is exactly what this exists to catch,
        // and for such a call an expectation usually matches: the test stubbed
        // `commit()` so it could return something. Deciding after the loop
        // would mean deciding after `recordMatch()` counted it and
        // `performAction()` ran the test's own `returns()`/`answers()` — a
        // refused call would have already moved the double's state.
        //
        // Cost is why this is one property read and a null check rather than
        // an accessor and a verdict to compare: no protocol is armed in the
        // overwhelming majority of calls, and everything on this path is paid
        // by every call in every suite. `make perf` measures it.
        $verdict = SequenceVerdict::NotWatched;
        $sequence = $context->armed;

        if ($sequence !== null) {
            $verdict = $sequence->offer($double, $invocation);

            if ($verdict === SequenceVerdict::OutOfTurn) {
                throw VerificationFailed::of([self::outOfTurn($state, $sequence, $invocation)]);
            }

            if ($verdict === SequenceVerdict::Advanced) {
                // A step is accounted for by the protocol that named it;
                // without this, `nothingElse()` would report the protocol's
                // own calls.
                $invocation->markAccounted();
            }
        }

        foreach ($state->expectationsFor($method, $invocation->args) as $expectation) {
            if (!$expectation->matchesArguments($invocation->args)) {
                continue;
            }

            // Counting and answering are separate concerns: an expectation
            // says how often a call may happen, an action says what it
            // answers. `expect(fn () => $repo->count())` is a complete
            // statement about the count, and forcing a `returns()` onto it
            // would be noise — so a matched expectation with no action falls
            // through to the mode's own answer below, having been counted.
            $expectation->recordMatch($invocation);
            $matched = true;

            // Only now, with the whole specification matched — a matcher is
            // asked about calls the rest of the specification then rejects,
            // and capturing there would record arguments of calls the captor
            // never named. The captor's lifetime is tied to the context that
            // owns the double, the same context the call log lives in.
            if ($expectation->hasCaptors()) {
                foreach ($expectation->captureFrom($invocation->args) as $captor) {
                    $context->rememberCaptor($captor);
                }
            }

            if (!$expectation->hasAction()) {
                break;
            }

            /** @var mixed $answer */
            $answer = $expectation->performAction($invocation);

            if ($signature !== null && $signature->returnsNever) {
                // The expectation returned instead of throwing; returning from
                // a `: never` method is a TypeError by language rule, so name
                // the real mistake rather than leaking that.
                throw NeverMethodCalled::configuredToReturn($state->label(), $method);
            }

            return $answer;
        }

        // Before the `never` fallback, because a forwarding double has a real
        // implementation to reach and that implementation is where a `: never`
        // method's throw lives. Complaining that nothing was configured would
        // be answering for an object that can answer for itself.
        //
        // Not gated on `$matched`, unlike strictness. Strict mode is a
        // complaint, and a matched expectation answers it; forwarding is the
        // mode's own answer, and an expectation that only counted the call —
        // `expect(...)->times(1)` with no action — still needs one. Returning a
        // default there would make counting a call change what it answers.
        if ($state->mode() === Mode::Forwarding) {
            // The live arguments, not the log's reading of them: a by-reference
            // parameter is the caller's variable, and the whole point of
            // forwarding is that the real method can write to it.
            return self::forward($state, $double, $method, $args);
        }

        if ($signature !== null && $signature->returnsNever) {
            // Which mistake it is depends on whether anything was configured:
            // "you never said what this throws" reads very differently from
            // "nothing expected this call at all".
            throw $matched
                ? NeverMethodCalled::withoutAnAction($state->label(), $method)
                : NeverMethodCalled::withoutExpectation($state->label(), $method);
        }

        // A double under protocol answers only for steps and for what the test
        // configured. Anything else is indistinguishable from a step arriving
        // out of turn — the protocol cannot tell "not part of this" from "you
        // got the order wrong" unless the test says which it is.
        if (!$matched && $verdict === SequenceVerdict::NotAStep) {
            \assert($sequence !== null);

            throw VerificationFailed::of([self::unconfiguredUnderProtocol($state, $sequence, $invocation)]);
        }

        // A matched expectation means the call was expected, so strictness has
        // nothing left to complain about.
        if (!$matched && $state->mode() === Mode::Strict) {
            // Every expectation for the method, not the indexed candidates the
            // dispatcher was offered: the index narrows by the first literal
            // argument, and a stub it skipped is exactly the near miss the
            // reader is looking for. Rendered only here, on the way to the
            // throw — a refusal that is not raised costs nothing.
            $candidates = $state->expectationsFor($method);

            throw StrictModeViolation::unexpectedCall(
                $state->label(),
                $method,
                $candidates === [] ? '' : FailureReport::renderRefusal($invocation, $candidates),
            );
        }

        return TypeDefaultResolver::forSignature(
            $state->label(),
            $signature,
            $method,
            $context,
            $state->nested,
            $double,
        );
    }

    /**
     * One rendering for both refusals: the protocol as a numbered list with
     * the step due marked, and the call that arrived. One alias table over all
     * of it, so an object in the step is comparable with the one in the call.
     *
     * @return non-empty-string
     */
    private static function describeProtocol(ArmedSequence $sequence, Invocation $invocation): string
    {
        return ArgumentFormatter::scope(static function () use ($sequence, $invocation): string {
            $call = FailureReport::renderCall($invocation);
            $lines = [];

            foreach ($sequence->describe() as $index => $step) {
                $lines[] = sprintf(
                    '    %d. %s%s',
                    $index + 1,
                    $step,
                    $index + 1 === $sequence->position() ? '   <- due here' : '',
                );
            }

            return sprintf("The call was:\n    %s\n\nThe protocol is:\n%s", $call, implode("\n", $lines));
        });
    }

    private static function outOfTurn(DoubleState $state, ArmedSequence $sequence, Invocation $invocation): VerificationFailure
    {
        return ArgumentFormatter::scope(static fn(): VerificationFailure => new VerificationFailure(
            kind: FailureKind::OutOfSequence,
            summary: sprintf(
                "Understudy `%s` received a protocol call out of turn: step %d of %d was expected to be `%s`.\n\n%s",
                $state->label(),
                $sequence->position(),
                $sequence->length(),
                $sequence->pending()?->describe() ?? 'nothing — the protocol has run out',
                self::describeProtocol($sequence, $invocation),
            ),
            double: $state->label(),
            expectation: $sequence->pending()?->describe(),
            observedCalls: [$invocation],
            expectedCalls: $sequence->describe(),
        ));
    }

    private static function unconfiguredUnderProtocol(DoubleState $state, ArmedSequence $sequence, Invocation $invocation): VerificationFailure
    {
        return ArgumentFormatter::scope(static fn(): VerificationFailure => new VerificationFailure(
            kind: FailureKind::OutOfSequence,
            summary: sprintf(
                "Understudy `%s` is under an armed protocol and received a call that is neither a step nor configured.\n\n%s\n\n"
                . 'Say it may happen — when(fn () => $double->%s(...))->returns(...) — or make it a step.',
                $state->label(),
                self::describeProtocol($sequence, $invocation),
                $invocation->method,
            ),
            double: $state->label(),
            expectation: $sequence->pending()?->describe(),
            observedCalls: [$invocation],
            expectedCalls: $sequence->describe(),
        ));
    }

    /**
     * Answers a read of a rendered hooked property.
     *
     * A property read is not a call, on purpose: it is not recorded, not
     * specifiable and not judged by strict mode or an armed protocol — the
     * same standing a plain public property already has on a class double.
     * What it answers, in order: the real instance's value when the double is
     * forwarding, the value the code under test wrote earlier, and otherwise
     * the mode's type-safe default — the same `TypeDefaultResolver` table a
     * method return goes through, `Understudy::defaults()` registrations and
     * the depth-1 nested double included.
     *
     * @param non-empty-string $property
     */
    public static function propertyRead(object $double, string $property): mixed
    {
        [$context, $state] = self::propertyState($double, $property);

        if ($state->mode() === Mode::Forwarding) {
            $target = $state->forwardingTarget();

            if ($target === null) {
                throw OriginalCallUnavailable::withoutTarget($state->label(), $property);
            }

            return $target->{$property};
        }

        if ($state->hasPropertyValue($property)) {
            return $state->propertyValue($property);
        }

        return TypeDefaultResolver::forPropertyType(
            $state->label(),
            $state->blueprint->property($property)?->type,
            $property,
            $context,
            $state->nested,
        );
    }

    /**
     * Records a write to a rendered hooked property, so a later read answers
     * it — the behaviour of a plain property, which is the least surprising
     * reading of "the contract says this is settable". A forwarding double
     * writes through to the real instance instead, the way it delegates a
     * call.
     *
     * @param non-empty-string $property
     */
    public static function propertyWrite(object $double, string $property, mixed $value): void
    {
        [, $state] = self::propertyState($double, $property);

        if ($state->mode() === Mode::Forwarding) {
            $target = $state->forwardingTarget();

            if ($target === null) {
                throw OriginalCallUnavailable::withoutTarget($state->label(), $property);
            }

            $target->{$property} = $value;

            return;
        }

        $state->writeProperty($property, $value);
    }

    /**
     * @param non-empty-string $property
     *
     * @return array{RuntimeContext, DoubleState}
     */
    private static function propertyState(object $double, string $property): array
    {
        $current = self::current();
        $state = $current->stateOf($double);
        $context = $current;

        if ($state === null) {
            $context = self::ownerOf($double) ?? $current;
            $state = $context->stateOf($double);
        }

        if ($state === null) {
            throw ForgottenDouble::propertyAfterReset($property);
        }

        return [$context, $state];
    }

    /**
     * Dispatches a call whose return type is by reference and hands back the
     * slot the generated method will return a reference into.
     *
     * The slot is seeded by the first call and then kept: a loose default
     * recomputes an empty value every time, and writing that back would undo
     * what the caller wrote through the reference it was given. An answer that
     * was actually configured still replaces it — a test that says what a
     * method returns means it.
     *
     * No `&` anywhere in this file: the reference is taken in the generated
     * method, on a plain property of the returned holder.
     *
     * @param non-empty-string $method
     * @param list<mixed>      $args
     */
    public static function referenceSlot(object $double, string $method, array $args): ReferenceSlot
    {
        $state = (self::ownerOf($double) ?? self::current())->stateOf($double);
        $configured = $state?->hasActionFor($method, $args) ?? false;

        /** @var mixed $value */
        $value = self::dispatch($double, $method, $args);

        $context = self::ownerOf($double) ?? self::current();
        $state = $context->stateOf($double);

        if ($state === null) {
            throw ForgottenDouble::afterReset($method);
        }

        return $state->referenceSlot($method, $value, replace: $configured);
    }

    /**
     * Delegates one recorded call to the real instance, from inside an answer.
     *
     * Explicit rather than mode-driven: `answers(fn (Invocation $i) =>
     * $i->callOriginal())` says "this one call goes through", and works whether
     * or not the double is forwarding by default.
     *
     * @param non-empty-string $method
     * @param list<mixed>      $args
     */
    public static function callOriginal(object $double, string $method, array $args): mixed
    {
        $context = self::ownerOf($double) ?? self::current();
        $state = $context->stateOf($double);

        if ($state === null) {
            throw ForgottenDouble::afterReset($method);
        }

        return self::forward($state, $double, $method, $args);
    }

    /**
     * Delegates a call to the double's real instance and adopts the result.
     *
     * Only the call at the boundary is recorded. If the real method calls
     * another method on itself, that happens inside the real object and never
     * reaches this dispatcher: understudy proxies an object, it does not
     * instrument one.
     *
     * @param non-empty-string $method
     * @param list<mixed>      $args
     */
    private static function forward(
        DoubleState $state,
        object $double,
        string $method,
        array $args,
    ): mixed {
        $target = $state->forwardingTarget();

        if ($target === null) {
            throw OriginalCallUnavailable::withoutTarget($state->label(), $method);
        }

        // Through a callable rather than `$target->{$method}()`: the method
        // name is only known at runtime, and a dynamic call on a bare `object`
        // tells static analysis nothing about what comes back.
        $call = [$target, $method];
        \assert(\is_callable($call));

        /** @var mixed $value */
        $value = $call(...$args);

        return self::adoptForwardedResult($state, $double, $target, $method, $value);
    }

    /**
     * A fluent method that returned the real instance has to come back as the
     * double: the caller holds the double, and handing it the real object would
     * quietly end the doubling halfway through a chain. Identity is what proves
     * it — `$value === $target`, not a class check.
     *
     * A `static` method that returned *another* instance of the real class
     * cannot be adopted. That object is not a generated subclass, so returning
     * it would violate the override's own `: static`, and wrapping it silently
     * would invent a double the test never asked for.
     *
     * @param non-empty-string $method
     */
    private static function adoptForwardedResult(
        DoubleState $state,
        object $double,
        object $target,
        string $method,
        mixed $value,
    ): mixed {
        if ($value === $target) {
            return $double;
        }

        $signature = $state->blueprint->method($method);

        if ($signature?->returnType === 'static' && $value instanceof $target) {
            throw OriginalReturnTypeViolation::foreignInstance($state->label(), $method, $value::class);
        }

        return $value;
    }

    /**
     * The same values, without the references.
     *
     * Copying an array whose elements are references keeps them references, so
     * a snapshot has to be built element by element. Without this the log would
     * hold one live view of the arguments rather than two readings of them.
     *
     * @param list<mixed> $args
     *
     * @return list<mixed>
     */
    private static function detached(array $args): array
    {
        return array_map(static fn(mixed $argument): mixed => self::detachValue($argument, 0), $args);
    }

    /**
     * One value, with the references inside it broken as well as the one on it.
     *
     * A by-reference parameter is often an array, and a reference can sit at any
     * depth in one: passing the top level through by value leaves a nested
     * `&$row` shared, and the "before" reading would then change under the
     * answer that wrote to it.
     *
     * Depth is capped for the same reason {@see ArgumentFormatter} caps it —
     * `$a[] = &$a` is legal PHP, and a snapshot that followed it would not
     * return. Past the cap the value is kept as it is: bounded work, and a
     * reading that is honest about how deep it looked. Objects are never
     * copied; a snapshot of one is the same object, which is what a caller
     * would see too.
     */
    private static function detachValue(mixed $value, int $depth): mixed
    {
        if (!\is_array($value) || $depth >= self::SNAPSHOT_DEPTH) {
            return $value;
        }

        return array_map(
            static fn(mixed $item): mixed => self::detachValue($item, $depth + 1),
            $value,
        );
    }

    /**
     * A matcher is part of the specification protocol, never a value. If one
     * arrives during a real call, the specification closure leaked it — say so
     * instead of letting the code under test receive a matcher object.
     *
     * The arity sentinel is the same kind of artifact: it exists so a
     * *specification* may stop before the required parameters run out, and a
     * real call it survives into is a call that omitted a required argument.
     * That is answered with the `ArgumentCountError` PHP itself would have
     * raised had the generated parameter kept its required arity — a double
     * must not be more permissive about arity than the real implementation.
     *
     * @param non-empty-string $method
     * @param list<mixed>      $args
     */
    private static function rejectLeakedMatchers(string $method, array $args): void
    {
        /** @var mixed $argument */
        foreach ($args as $position => $argument) {
            if ($argument instanceof ArgumentMatcher) {
                throw MatcherLeaked::intoRealCall($method, $position, $argument->describe());
            }

            if ($argument instanceof Absent) {
                throw new \ArgumentCountError(sprintf(
                    'Too few arguments to function %s(), argument #%d not passed',
                    $method,
                    $position + 1,
                ));
            }
        }
    }

    /**
     * Drops the current execution context. Adapters call this unconditionally
     * after each test; sibling Fibers must remain untouched.
     */
    public static function reset(): void
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber === null) {
            $stack = self::$main;
        } else {
            $fibers = self::fibers();
            /** @var list<RuntimeContext> $stack */
            $stack = $fibers->offsetExists($fiber) ? $fibers->offsetGet($fiber) : [];
        }

        // The sweep retires every live context, this one included — a context
        // is recorded live where it is created, so there is nothing to retire
        // separately here. It runs before the replacement is opened, because
        // it would otherwise retire that one too.
        self::forgetOrphans();

        $position = count($stack) - 1;

        if ($position < 0) {
            return;
        }

        $stack[$position] = self::freshContext();
        // Replacing one slot of a list keeps it a list; psalm widens it to a
        // plain array because the offset is a variable.
        /** @var list<RuntimeContext> $stack */

        if ($fiber === null) {
            self::$main = $stack;

            return;
        }

        self::fibers()->offsetSet($fiber, $stack);
    }

    /**
     * The one place a context comes into being, and therefore the one place it
     * is recorded as live. Recording it per double adopted into it rewrote the
     * same key on the hottest creation path there is; recording it here happens
     * once, and no context can be created without it.
     */
    private static function freshContext(): RuntimeContext
    {
        $context = new RuntimeContext();
        self::remember($context);

        return $context;
    }

    /**
     * Drops every context still holding understudies that the caller is not
     * standing in — a Fiber's context, most of all.
     *
     * Unconditional, including when `reset()` is itself called from inside a
     * Fiber, because that is the shape the runners actually have. Under Testo
     * a `#[RunInFiber]` test puts the whole pipeline in one Fiber, and the
     * assert collector then opens a SECOND one around the body: the adapter's
     * teardown runs in the outer Fiber while the test's understudies live in
     * the inner one. A reset that only reached its own Fiber left them behind
     * to answer the next test.
     */
    private static function forgetOrphans(): void
    {
        foreach (self::$live as $context) {
            self::retire($context);
        }

        self::$live = [];
    }

    /**
     * Stamps every double the context held as forgotten and drops its owner.
     *
     * The loop looks like something to remove — teardown runs after every test
     * — and removing it was measured. Marking the context instead and having
     * `ownerOf()` read the flag made teardown cheaper in isolation and double
     * creation 13-23% slower in the benchmark, because the owner entries then
     * live until collection and the map they sit in keeps growing. Eager
     * removal here is what keeps that map the size of one test.
     */
    private static function retire(RuntimeContext $context): void
    {
        self::unremember($context);

        // Captured values live exactly as long as the call log of the context
        // they were captured in. The captor object itself survives — it is a
        // test-owned reader — but holds nothing from a test that ended.
        foreach ($context->captors() as $captor) {
            $captor->discard();
        }

        if (self::$owners === null) {
            return;
        }

        foreach ($context->allDoubles() as $double) {
            self::forgotten()[$double] = true;
            unset(self::$owners[$double]);
        }
    }
}
