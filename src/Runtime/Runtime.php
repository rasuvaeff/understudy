<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Runtime;

use Rasuvaeff\Understudy\Defaults\TypeDefaultResolver;
use Rasuvaeff\Understudy\Exception\ForgottenDouble;
use Rasuvaeff\Understudy\Exception\MatcherLeaked;
use Rasuvaeff\Understudy\Exception\NeverMethodCalled;
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
            array_pop(self::$main);

            return;
        }

        $fibers = self::fibers();
        /** @var list<RuntimeContext> $stack */
        $stack = $fibers->offsetExists($fiber) ? $fibers->offsetGet($fiber) : [];
        array_pop($stack);
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

        $invocation = new Invocation($method, $args, $context->nextSequence());
        $state->record($invocation);

        try {
            /** @var mixed $value */
            $value = self::answer($state, $method, $invocation);
        } catch (\Throwable $thrown) {
            $invocation->recordOutcome(Outcome::thrownError($thrown));

            throw $thrown;
        }

        $invocation->recordOutcome(Outcome::returnedValue($value));

        return $value;
    }

    /**
     * @param non-empty-string $method
     */
    private static function answer(DoubleState $state, string $method, Invocation $invocation): mixed
    {
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

        return TypeDefaultResolver::forSignature($state->label(), $signature, $method);
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
     * Drops every context. Adapters call this unconditionally after each test.
     */
    public static function reset(): void
    {
        self::$main = [];
        self::$fibers = null;
        self::$owners = null;
    }
}
