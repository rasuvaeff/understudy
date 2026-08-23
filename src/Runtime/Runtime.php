<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Runtime;

use Rasuvaeff\Understudy\Codegen\DoubleFactory;
use Rasuvaeff\Understudy\Defaults\TypeDefaultResolver;
use Rasuvaeff\Understudy\Exception\ForgottenDouble;
use Rasuvaeff\Understudy\Exception\MatcherLeaked;
use Rasuvaeff\Understudy\Exception\NeverMethodCalled;
use Rasuvaeff\Understudy\Exception\OriginalCallUnavailable;
use Rasuvaeff\Understudy\Exception\OriginalReturnTypeViolation;
use Rasuvaeff\Understudy\Exception\StrictModeViolation;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Matcher\ArgumentMatcher;
use Rasuvaeff\Understudy\Outcome;

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

    public static function current(): RuntimeContext
    {
        $stack = self::stack();

        if ($stack === []) {
            $context = new RuntimeContext();
            self::push($context);

            return $context;
        }

        return $stack[count($stack) - 1];
    }

    /**
     * Opens a nested context, so that everything created inside it is dropped
     * when it closes and the enclosing context resumes untouched.
     */
    public static function pushScope(): RuntimeContext
    {
        $context = new RuntimeContext();
        self::push($context);

        return $context;
    }

    public static function popScope(): void
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber === null) {
            $context = array_pop(self::$main);

            if ($context instanceof RuntimeContext) {
                self::forget($context);
            }

            return;
        }

        $fibers = self::fibers();
        /** @var list<RuntimeContext> $stack */
        $stack = $fibers->offsetExists($fiber) ? $fibers->offsetGet($fiber) : [];
        $context = array_pop($stack);

        if ($context instanceof RuntimeContext) {
            self::forget($context);
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
        $blueprint = DoubleFactory::blueprintFor([$contract]);
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
        if (self::current()->isRecording()) {
            throw new InvocationSignal($double, $method, $args);
        }

        self::rejectLeakedMatchers($method, $args);

        $context = self::ownerOf($double) ?? self::current();
        $state = $context->stateOf($double);

        if ($state === null) {
            // Returning null here would violate the declared return type of
            // any non-nullable method, so say what actually happened.
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

            $invocation->recordOutcome(Outcome::thrownError($thrown));

            throw $thrown;
        }

        if ($tracksReferences) {
            $invocation->recordFinalArguments(self::detached($args));
        }

        $invocation->recordOutcome(Outcome::returnedValue($value));

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

        foreach ($state->expectations() as $expectation) {
            if (!$expectation->matches($method, $invocation->args)) {
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

        // A matched expectation means the call was expected, so strictness has
        // nothing left to complain about.
        if (!$matched && $state->mode() === Mode::Strict) {
            throw StrictModeViolation::unexpectedCall($state->label(), $method);
        }

        return TypeDefaultResolver::forSignature($state->label(), $signature, $method, $context, $state->nested);
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
     * Drops the current execution context. Adapters call this unconditionally
     * after each test; sibling Fibers must remain untouched.
     */
    public static function reset(): void
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber === null) {
            if (self::$main === []) {
                return;
            }

            $position = count(self::$main) - 1;
            self::forget(self::$main[$position]);
            self::$main[$position] = new RuntimeContext();

            return;
        }

        $fibers = self::fibers();

        if (!$fibers->offsetExists($fiber)) {
            return;
        }

        /** @var list<RuntimeContext> $stack */
        $stack = $fibers->offsetGet($fiber);

        if ($stack === []) {
            return;
        }

        $position = count($stack) - 1;
        self::forget($stack[$position]);
        $stack[$position] = new RuntimeContext();
        $fibers->offsetSet($fiber, $stack);
    }

    private static function forget(RuntimeContext $context): void
    {
        if (self::$owners === null) {
            return;
        }

        $forgotten = [];

        foreach (self::$owners as $double => $owner) {
            if ($owner === $context) {
                $forgotten[] = $double;
            }
        }

        foreach ($forgotten as $double) {
            unset(self::$owners[$double]);
        }
    }
}
