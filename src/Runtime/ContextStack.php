<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Runtime;

/**
 * Which context is current — per Fiber, nested by `scope()` — and every
 * context still holding understudies.
 *
 * @internal
 */
final class ContextStack
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

    private function __construct() {}

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

    /**
     * Closes the innermost scope of the caller and retires its context.
     */
    public static function popScope(): void
    {
        $fiber = \Fiber::getCurrent();

        if (!$fiber instanceof \Fiber) {
            $context = array_pop(self::$main);

            if ($context instanceof RuntimeContext) {
                self::retire($context, byScope: true);
            }

            return;
        }

        $fibers = self::fibers();
        /** @var list<RuntimeContext> $stack */
        $stack = $fibers->offsetExists($fiber) ? $fibers->offsetGet($fiber) : [];
        $context = array_pop($stack);

        if ($context instanceof RuntimeContext) {
            self::retire($context, byScope: true);
        }

        $fibers->offsetSet($fiber, $stack);
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
        $contexts = $current instanceof \Rasuvaeff\Understudy\Runtime\RuntimeContext ? [$current] : [];

        foreach (self::$live as $context) {
            if ($context !== $current) {
                $contexts[] = $context;
            }
        }

        return $contexts;
    }

    /**
     * Drops the current execution context. Adapters call this unconditionally
     * after each test; sibling Fibers must remain untouched.
     *
     * The sweep retires every live context, this one included — a context is
     * recorded live where it is created, so there is nothing to retire
     * separately here. It runs before the replacement is opened, because it
     * would otherwise retire that one too.
     *
     * Unconditional, including when `reset()` is itself called from inside a
     * Fiber, because that is the shape the runners actually have. Under Testo
     * a `#[RunInFiber]` test puts the whole pipeline in one Fiber, and the
     * assert collector then opens a SECOND one around the body: the adapter's
     * teardown runs in the outer Fiber while the test's understudies live in
     * the inner one. A reset that only reached its own Fiber left them behind
     * to answer the next test.
     *
     * One method on purpose: teardown runs after every test, and each static
     * call split out of it is measurable in `make perf`.
     */
    public static function reset(): void
    {
        foreach (self::$live as $context) {
            self::retire($context);
        }

        self::$live = [];

        $fiber = \Fiber::getCurrent();

        if (!$fiber instanceof \Fiber) {
            $stack = self::$main;
        } else {
            $fibers = self::fibers();
            /** @var list<RuntimeContext> $stack */
            $stack = $fibers->offsetExists($fiber) ? $fibers->offsetGet($fiber) : [];
        }

        $position = count($stack) - 1;

        if ($position < 0) {
            return;
        }

        $stack[$position] = self::freshContext();
        // Replacing one slot of a list keeps it a list; psalm widens it to a
        // plain array because the offset is a variable.
        /** @var list<RuntimeContext> $stack */

        if (!$fiber instanceof \Fiber) {
            self::$main = $stack;

            return;
        }

        self::fibers()->offsetSet($fiber, $stack);
    }

    /**
     * Captured values live exactly as long as the call log of the context
     * they were captured in. The captor object itself survives — it is a
     * test-owned reader — but holds nothing from a test that ended.
     */
    private static function retire(
        RuntimeContext $context,
        bool $byScope = false,
    ): void {
        unset(self::$live[spl_object_id($context)]);

        foreach ($context->captors() as $captor) {
            $captor->discard();
        }

        Ownership::retire($context, $byScope);
    }

    /**
     * @return list<RuntimeContext>
     */
    private static function stack(): array
    {
        $fiber = \Fiber::getCurrent();

        if (!$fiber instanceof \Fiber) {
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

        if (!$fiber instanceof \Fiber) {
            self::$main[] = $context;

            return;
        }

        $stack = self::stack();
        $stack[] = $context;
        self::fibers()->offsetSet($fiber, $stack);
    }

    /**
     * The one place a context comes into being, and therefore the one place it
     * is recorded as live. Recording it per double adopted into it rewrote the
     * same key on the hottest creation path there is; recording it here happens
     * once, and no context can be created without it. A context that ends up
     * holding nothing is harmless — accounting walks its states, and it has
     * none.
     */
    private static function freshContext(): RuntimeContext
    {
        $context = new RuntimeContext();
        self::$live[spl_object_id($context)] = $context;

        return $context;
    }

    /**
     * @return \WeakMap<\Fiber, list<RuntimeContext>>
     */
    private static function fibers(): \WeakMap
    {
        if (!self::$fibers instanceof \WeakMap) {
            /** @var \WeakMap<\Fiber, list<RuntimeContext>> $fibers */
            $fibers = new \WeakMap();
            self::$fibers = $fibers;
        }

        return self::$fibers;
    }
}
