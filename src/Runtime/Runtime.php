<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Runtime;

use Rasuvaeff\Understudy\Defaults\TypeDefaultResolver;
use Rasuvaeff\Understudy\Exception\NeverMethodCalled;
use Rasuvaeff\Understudy\Exception\StrictModeViolation;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Outcome;

/**
 * The registry generated doubles call into, and the owner of every context.
 *
 * @internal
 */
final class Runtime
{
    private static ?RuntimeContext $main = null;

    /** @var \WeakMap<\Fiber, RuntimeContext>|null */
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
        $fiber = \Fiber::getCurrent();

        if ($fiber === null) {
            return self::$main ??= new RuntimeContext();
        }

        $fibers = self::fibers();

        if (!$fibers->offsetExists($fiber)) {
            $context = new RuntimeContext();
            $fibers->offsetSet($fiber, $context);

            return $context;
        }

        // WeakMap::offsetGet() is typed as nullable regardless of the value
        // type, and offsetExists() above has already ruled that out.
        /** @var RuntimeContext $context */
        $context = $fibers->offsetGet($fiber);

        return $context;
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
     * @return \WeakMap<\Fiber, RuntimeContext>
     */
    private static function fibers(): \WeakMap
    {
        if (self::$fibers === null) {
            /** @var \WeakMap<\Fiber, RuntimeContext> $fibers */
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
        $context = self::ownerOf($double) ?? self::current();

        if ($context->isRecording()) {
            throw new InvocationSignal($double, $method, $args);
        }

        $state = $context->stateOf($double);

        if ($state === null) {
            // Only reachable if a double outlived a reset(); treat it as loose
            // with no configuration rather than corrupting another context.
            return null;
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
        foreach ($state->expectations() as $expectation) {
            if ($expectation->matches($method, $invocation->args)) {
                return $expectation->answer($invocation);
            }
        }

        $signature = $state->blueprint->method($method);

        if ($signature !== null && $signature->returnsNever) {
            throw NeverMethodCalled::withoutExpectation($state->label(), $method);
        }

        if ($state->mode() === Mode::Strict) {
            throw StrictModeViolation::unexpectedCall($state->label(), $method);
        }

        return TypeDefaultResolver::forSignature($state->label(), $signature, $method);
    }

    /**
     * Drops every context. Adapters call this unconditionally after each test.
     */
    public static function reset(): void
    {
        self::$main = null;
        self::$fibers = null;
        self::$owners = null;
    }
}
