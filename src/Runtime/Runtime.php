<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Runtime;

use Rasuvaeff\Understudy\Codegen\DoubleFactory;
use Rasuvaeff\Understudy\Codegen\MethodSignature;
use Rasuvaeff\Understudy\Defaults\TypeDefaultResolver;
use Rasuvaeff\Understudy\Exception\MatcherLeaked;
use Rasuvaeff\Understudy\Exception\NeverMethodCalled;
use Rasuvaeff\Understudy\Exception\OriginalCallUnavailable;
use Rasuvaeff\Understudy\Exception\StrictModeViolation;
use Rasuvaeff\Understudy\Exception\VerificationFailed;
use Rasuvaeff\Understudy\Expectation\ArgumentFormatter;
use Rasuvaeff\Understudy\FailureReport;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Matcher\ArgumentMatcher;

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
     * Opens a nested context, so that everything created inside it is dropped
     * when it closes and the enclosing context resumes untouched.
     */
    public static function pushScope(): RuntimeContext
    {
        return ContextStack::pushScope();
    }

    public static function popScope(): void
    {
        $context = ContextStack::popScope();

        if ($context instanceof RuntimeContext) {
            self::retire($context, byScope: true);
        }
    }

    public static function adopt(object $double, DoubleState $state): void
    {
        $context = ContextStack::current();
        $context->register($double, $state);
        Ownership::owners()->offsetSet($double, $context);
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

        if (!$blueprint instanceof \Rasuvaeff\Understudy\Codegen\Blueprint) {
            // Only a generated class reaches this method, and every one of them
            // is registered when it is compiled.
            return;
        }

        $context = ContextStack::current();
        $state = new DoubleState($blueprint);

        Forwarder::inheritForwarding($clone, $state, $context);

        $context->register($clone, $state);

        Ownership::owners()->offsetSet($clone, $context);
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
        Ownership::owners()->offsetSet($double, $owner);

        return $double;
    }

    public static function stateOf(object $double): ?DoubleState
    {
        return Ownership::ownerOf($double)?->stateOf($double);
    }

    public static function isOwnedByCurrentContext(object $double): bool
    {
        $owner = Ownership::ownerOf($double);

        return $owner instanceof \Rasuvaeff\Understudy\Runtime\RuntimeContext && $owner === ContextStack::current();
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
        $current = ContextStack::current();

        if ($current->isRecording()) {
            // The signal is retained, not thrown: the closure goes on, so a
            // second call — `fn () => $r->find($r->count())` dispatches
            // `count()` first — is seen and refused by the recorder instead
            // of being specified silently. The call is answered with the
            // mode's type-safe default out of a context nobody else holds,
            // `nested` so that no collaborator is invented along the way.
            // Where no default exists (`: never`, an object type) the signal
            // is thrown after all and the closure ends here, as every
            // recording used to.
            $call = new SpecificationCall($double, $method, $args);
            $current->recordSpecificationCall($call);
            $signature = DoubleFactory::blueprintOfGenerated($double::class)?->method($method);

            try {
                return TypeDefaultResolver::forSignature(
                    self::stateOf($double)?->label() ?? 'understudy',
                    $signature,
                    $method,
                    new RuntimeContext(),
                    nested: true,
                    double: $double,
                );
            } catch (\Throwable) {
                throw new InvocationSignal($call);
            }
        }

        self::rejectLeakedMatchers($method, $args);

        // Most calls stay in the context that created the double. Avoid the
        // WeakMap owner lookup on that hot path; retain it for cross-Fiber
        // calls where the current context does not know the object.
        $context = $current;
        $state = $current->stateOf($double);

        if (!$state instanceof \Rasuvaeff\Understudy\Runtime\DoubleState) {
            $context = Ownership::ownerOf($double) ?? $current;
            $state = $context->stateOf($double);
        }

        if (!$state instanceof \Rasuvaeff\Understudy\Runtime\DoubleState) {
            // Returning null here would violate the declared return type of
            // any non-nullable method, so say what actually happened. The
            // message differs for a double retired through `Understudy::forget()`:
            // sending the reader looking for a stray reset() they never wrote
            // is worse than the plain truth.
            throw Ownership::forgottenDouble($double, $method);
        }

        // A by-reference argument is live: whatever answers the call can write
        // through it, and the log would then show a value the caller never
        // passed. Both sides are kept, and only for the methods that can move —
        // snapshotting every call would cost the whole suite for a rare case.
        $signature = $state->blueprint->method($method);
        $tracksReferences = $signature?->hasReferenceParameters ?? false;

        // Whatever the caller left out arrives as the sentinel. A real call
        // gets the contract's own default put back, so that `tag('alpha')`
        // and `tag('alpha', 1)` are still the same call in the log; a
        // position the contract declares required has nothing to put back
        // and raises the error PHP itself would have raised.
        self::materializeOmittedArguments($signature, $method, $args);

        $invocation = new Invocation(
            method: $method,
            args: $tracksReferences ? self::detached($args) : $args,
            sequence: $context->nextSequence(),
            double: $double,
            liveArgs: $args,
            sensitiveArguments: $signature?->sensitiveParameters ?? [],
            parameterNames: $signature?->parameterNames ?? [],
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

        if ($sequence instanceof \Rasuvaeff\Understudy\Runtime\ArmedSequence) {
            $verdict = $sequence->offer($double, $invocation);

            if ($verdict === SequenceVerdict::OutOfTurn) {
                throw VerificationFailed::of([FailureReport::outOfTurn($state->label(), $sequence, $invocation, $double)]);
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

            // The test's own closure, so a `clone $double` inside it is the
            // test's clone even when a real body is on the stack underneath.
            // Two integers rather than a saved copy of the stack, and written
            // here rather than behind a `Forwarder` method: this is the
            // dispatch path, and every stubbed call in a suite pays for it.
            $floor = Forwarder::$inlineFloor;
            Forwarder::$inlineFloor = \count(Forwarder::$inlineFrames);

            try {
                /** @var mixed $answer */
                $answer = $expectation->performAction($invocation);
            } finally {
                Forwarder::$inlineFloor = $floor;
            }

            if ($signature instanceof \Rasuvaeff\Understudy\Codegen\MethodSignature && $signature->returnsNever) {
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
            if ($state->forwardsInline()) {
                return Forwarder::runInline($state, $double, $method, $args);
            }

            // The live arguments, not the log's reading of them: a by-reference
            // parameter is the caller's variable, and the whole point of
            // forwarding is that the real method can write to it.
            return Forwarder::forward($state, $double, $method, $args);
        }

        if ($signature instanceof \Rasuvaeff\Understudy\Codegen\MethodSignature && $signature->returnsNever) {
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
        if ($sequence instanceof ArmedSequence && !$matched && $verdict === SequenceVerdict::NotAStep) {
            throw VerificationFailed::of([FailureReport::unconfiguredUnderProtocol($state->label(), $sequence, $invocation, $double)]);
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
        $current = ContextStack::current();
        $state = $current->stateOf($double);
        $context = $current;

        if (!$state instanceof \Rasuvaeff\Understudy\Runtime\DoubleState) {
            $context = Ownership::ownerOf($double) ?? $current;
            $state = $context->stateOf($double);
        }

        if (!$state instanceof \Rasuvaeff\Understudy\Runtime\DoubleState) {
            throw Ownership::forgottenProperty($double, $property);
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
        $state = (Ownership::ownerOf($double) ?? ContextStack::current())->stateOf($double);

        // Before `hasActionFor()`, not after: it walks the expectations the
        // way dispatch will, and dispatch will see the arguments with the
        // omitted ones put back. Asking it about a sentinel would answer
        // "nothing configured" for a specification that spells the default,
        // and the slot would keep the mode's own value instead of the
        // configured one. A recording phase is the exception — the sentinel
        // is what it came for.
        if ($state instanceof \Rasuvaeff\Understudy\Runtime\DoubleState && !ContextStack::current()->isRecording()) {
            self::materializeOmittedArguments($state->blueprint->method($method), $method, $args);
        }

        $configured = $state?->hasActionFor($method, $args) ?? false;

        /** @var mixed $value */
        $value = self::dispatch($double, $method, $args);

        if (ContextStack::current()->isRecording()) {
            // A recording answers with a throwaway: the per-method slot is
            // state the test can observe through the reference it already
            // holds, and the closure's own value must not land in it.
            $slot = new ReferenceSlot();
            $slot->value = $value;

            return $slot;
        }

        $context = Ownership::ownerOf($double) ?? ContextStack::current();
        $state = $context->stateOf($double);

        if (!$state instanceof \Rasuvaeff\Understudy\Runtime\DoubleState) {
            throw Ownership::forgottenDouble($double, $method);
        }

        return $state->referenceSlot($method, $value, replace: $configured);
    }

    /**
     * Runs the real implementation of one recorded call, from inside an answer.
     *
     * Explicit rather than mode-driven: `answers(fn (Invocation $i) =>
     * $i->callOriginal())` says "this one call goes through", and works whether
     * or not the double is forwarding by default.
     *
     * Whichever form the double forwards in: an inline double runs the real
     * body on itself, because the state that body reads lives on the double
     * from the moment `forwarding()` copied it — the donor's own copy is
     * whatever the first inline call left behind. Everything else proxies to
     * the remembered instance.
     *
     * @param non-empty-string $method
     * @param list<mixed>      $args
     */
    public static function callOriginal(object $double, string $method, array $args): mixed
    {
        $context = Ownership::ownerOf($double) ?? ContextStack::current();
        $state = $context->stateOf($double);

        if (!$state instanceof \Rasuvaeff\Understudy\Runtime\DoubleState) {
            throw Ownership::forgottenDouble($double, $method);
        }

        if ($state->forwardsInline()) {
            return Forwarder::runInline($state, $double, $method, $args);
        }

        return Forwarder::forward($state, $double, $method, $args);
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
        }
    }

    /**
     * Puts the contract's declared default back into every parameter the
     * caller omitted.
     *
     * Every generated parameter defaults to the sentinel, optional ones
     * included, so that a *specification* can leave a parameter unspelled
     * without the default value standing in for it. A real call wants the
     * opposite: the log has to show what the method received, so an omitted
     * argument reads as the value the real implementation would have seen.
     *
     * The sentinel surviving on a position the contract declares required is
     * a call that omitted a required argument, and it is answered with the
     * `ArgumentCountError` PHP itself would have raised had the generated
     * parameter kept its arity — a double must not be more permissive about
     * arity than the real implementation.
     *
     * @param non-empty-string $method
     * @param list<mixed>      $args
     *
     * @param-out list<mixed> $args
     */
    private static function materializeOmittedArguments(?MethodSignature $signature, string $method, array &$args): void
    {
        $omitted = [];

        /** @var mixed $argument */
        foreach ($args as $position => $argument) {
            if (!$argument instanceof Absent) {
                continue;
            }

            if (!$signature instanceof \Rasuvaeff\Understudy\Codegen\MethodSignature || !$signature->isOptional($position)) {
                throw new \ArgumentCountError(sprintf(
                    'Too few arguments to function %s(), argument #%d not passed',
                    $method,
                    $position + 1,
                ));
            }

            $omitted[] = $position;
        }

        if ($omitted === []) {
            return;
        }

        \assert($signature instanceof MethodSignature);

        // `array_replace` rather than a rebuilt list: it keeps the reference
        // elements a by-reference parameter is collected with — measured, not
        // assumed — and a forwarded call must still be able to write back to
        // the caller's variable.
        $args = array_replace($args, array_combine(
            $omitted,
            array_map(
                $signature->defaultAt(...),
                $omitted,
            ),
        ));
    }

    /**
     * Drops the current execution context. Adapters call this unconditionally
     * after each test; sibling Fibers must remain untouched.
     */
    public static function reset(): void
    {
        // The sweep retires every live context, this one included — a context
        // is recorded live where it is created, so there is nothing to retire
        // separately here. It runs before the replacement is opened, because
        // it would otherwise retire that one too. Drained, so each one is
        // already off the live list.
        foreach (ContextStack::drainLive() as $context) {
            self::discardCaptors($context);
            Ownership::retire($context);
        }

        ContextStack::renewCurrent();
    }

    private static function retire(
        RuntimeContext $context,
        bool $byScope = false,
    ): void {
        ContextStack::unremember($context);
        self::discardCaptors($context);
        Ownership::retire($context, $byScope);
    }

    /**
     * Captured values live exactly as long as the call log of the context
     * they were captured in. The captor object itself survives — it is a
     * test-owned reader — but holds nothing from a test that ended.
     */
    private static function discardCaptors(RuntimeContext $context): void
    {
        foreach ($context->captors() as $captor) {
            $captor->discard();
        }
    }
}
