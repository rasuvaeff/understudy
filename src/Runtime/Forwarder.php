<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Runtime;

use Rasuvaeff\Understudy\Exception\OriginalCallUnavailable;
use Rasuvaeff\Understudy\Exception\OriginalReturnTypeViolation;
use Rasuvaeff\Understudy\Expectation\Expectation;
use Rasuvaeff\Understudy\Invocation;

/**
 * Runs a double's real implementation: inline on the double itself, or by
 * proxy to the remembered instance — and keeps the stack of real bodies a
 * `clone` made inside one of them is judged against.
 *
 * @internal
 */
final class Forwarder
{
    /**
     * The doubled classes' own method bodies, by `Class::method`, for inline
     * forwarding. Class metadata, so it outlives a context: `reset()` drops
     * doubles, not the shape of the types they stood in for.
     *
     * @var array<string, \ReflectionMethod>
     */
    private static array $inlineMethods = [];

    /**
     * The doubles whose real bodies are running right now, innermost last.
     *
     * A body that clones `$this` gets a copy of a double, and the generated
     * `__clone()` cannot tell what it was cloned from — PHP hands it no
     * reference to the original. Without this frame the copy would be a
     * default-answering double in the middle of real code that, proxied,
     * would have gone on working: `withTag()` returning `clone $this`, or a
     * body computing over an internal copy.
     *
     * @var list<object>
     */
    private static array $inlineFrames = [];

    /**
     * How much of {@see $inlineFrames} a `clone` is allowed to see.
     *
     * A test's own `answers()`/`returns()` closure runs inside dispatch, which
     * can be reached from a real body running inline — and a `clone $double`
     * written there is the test's clone, which the documented rule makes a
     * fresh double. The frames below the floor are the bodies that happen to
     * be on the stack underneath; the floor is raised for the span of the
     * closure, so only what the closure itself enters — `callOriginal()`,
     * which pushes a frame of its own — is visible to a clone made inside it.
     */
    private static int $inlineFloor = 0;

    private function __construct() {}

    /**
     * Runs the test's own `returns()`/`answers()` with the frame floor raised.
     *
     * The test's own closure, so a `clone $double` inside it is the test's
     * clone even when a real body is on the stack underneath. Two integers
     * rather than a saved copy of the stack: this is the dispatch path, and
     * every stubbed call in a suite pays for it.
     */
    public static function performAction(Expectation $expectation, Invocation $invocation): mixed
    {
        $floor = self::$inlineFloor;
        self::$inlineFloor = \count(self::$inlineFrames);

        try {
            return $expectation->performAction($invocation);
        } finally {
            self::$inlineFloor = $floor;
        }
    }

    /**
     * Gives a fresh clone the forwarding of the double whose real body made
     * it, when one is running; see {@see Runtime::adoptClone()}.
     */
    public static function inheritForwarding(object $clone, DoubleState $state, RuntimeContext $context): void
    {
        // Cloned by real code running inline: the copy carries the original's
        // forwarding, because PHP already copied the state those bodies read
        // and the alternative is real code holding a double that answers
        // defaults. Expectations are still not copied — that part of the rule
        // in `Runtime::adoptClone()` stands, and a clone the TEST makes is
        // untouched by this, whether no body is running at all or the test's
        // own closure was reached from one ({@see $inlineFloor}).
        $frames = self::$inlineFrames;
        $frame = $frames === [] || \count($frames) <= self::$inlineFloor
            ? null
            : $frames[\count($frames) - 1];

        // The class is not enough, and the same generated class stands in for
        // every double of a type: a body handed another double of its own
        // class — `merge(Basket $other)` — and cloning THAT would otherwise
        // adopt it on the running double's behalf, giving it bodies to run and
        // no state copy to run them over. What PHP leaves to go on is the copy
        // itself, which at this moment is property-identical to whatever it
        // was cloned from, because the generated `__clone()` does nothing but
        // call `Runtime::adoptClone()`.
        if ($frame !== null && $frame::class === $clone::class && self::isCopyOf($clone, $frame)) {
            $origin = (Ownership::ownerOf($frame) ?? $context)->stateOf($frame);
            $target = $origin?->forwardingTarget();
            $parent = $origin?->inlineParent();

            if ($target !== null && $parent !== null) {
                $state->setForwardingTarget($target);
                $state->setMode(Mode::Forwarding);
                $state->forwardInline($parent);
            }
        }
    }

    /**
     * Runs the real body on the double itself.
     *
     * `ReflectionMethod::invokeArgs()` is what makes this possible without
     * adding anything to the generated class: a reflection taken on the
     * *doubled* class enters that class's body even though the double
     * overrides the method — the same non-virtual entry `parent::` gives —
     * while `$this` inside the body stays the double, so its self-calls
     * dispatch virtually and land back in the override. Verified on 8.3, 8.4
     * and 8.5; `spikes/08-inline-forwarding` is the executable form of the
     * claim. A generated `__understudyReal_*()` sibling would be the other
     * way, and it is the way this library does not take: every member added
     * to a double is a name the doubled type can no longer use.
     *
     * The arguments are the live ones — a reference survives the array, so a
     * by-reference parameter still writes to the caller's variable — and the
     * body runs inside dispatch, so its outcome is recorded like any other
     * answer's.
     *
     * The one thing the proxy form refused and this form has to refuse too: a
     * real body that builds another instance of itself and hands it out. Under
     * the proxy it was an instance of the real class the double could not
     * stand in for; here it is an instance of the generated class, which
     * satisfies the override's `: static` and so gets past the language. A
     * `clone $this` is fine — `adoptClone()` gave the copy this double's
     * forwarding, so it answers for real — but a `new static` belongs to no
     * context at all, and the next call on it would fail as a double that was
     * never registered. That is what is refused, by registration rather than
     * by return type: an unknown instance is broken for every caller, not only
     * for one whose signature says `static`.
     *
     * @param non-empty-string $method
     * @param list<mixed>      $args
     */
    public static function runInline(DoubleState $state, object $double, string $method, array $args): mixed
    {
        $parent = $state->inlineParent();
        \assert($parent !== null);

        // Cached across the suite: the reflection describes a class, and a
        // fresh one per call is reflection on the dispatch path. Every contract
        // of the double is satisfied by the donor's class, which IS this class,
        // so the method is always there to be found.
        $real = self::$inlineMethods[$parent . '::' . $method] ??= new \ReflectionMethod($parent, $method);

        self::$inlineFrames[] = $double;

        try {
            /** @var mixed $value */
            $value = $real->invokeArgs($double, $args);
        } finally {
            array_pop(self::$inlineFrames);
        }

        if (!\is_object($value)) {
            // Only an object can be the double, its clone, or a `new static`.
            return $value;
        }

        if ($value === $double) {
            // The body's own `return $this`, which already IS the double.
            return $value;
        }

        if ($value instanceof $double && !Ownership::ownerOf($value) instanceof \Rasuvaeff\Understudy\Runtime\RuntimeContext) {
            throw OriginalReturnTypeViolation::foreignSelf($state->label(), $method);
        }

        return $value;
    }

    /**
     * Delegates a call to the double's real instance and adopts the result.
     *
     * The proxy form of forwarding — the one an interface double has no choice
     * about, and the one a donor that is a subclass of the doubled class keeps.
     * The call runs on the remembered instance, `$this` inside it is that
     * instance, and its self-calls stay inside it. A class double standing in
     * for exactly the donor's class runs the real bodies on itself instead
     * ({@see runInline()}), where `$this` stays the double and self-calls
     * re-enter dispatch.
     *
     * @param non-empty-string $method
     * @param list<mixed>      $args
     */
    public static function forward(
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
     * Copies the donor instance's initialized state onto the double, so a
     * parent body run inline works over the values the real object held.
     *
     * The double's constructor never ran — beyond the empty scalars
     * `PropertyDefaults` wrote, nothing on it is initialized — while the
     * parent bodies inline forwarding runs read `$this->` properties. Every
     * property the donor has initialized is copied; one the donor left
     * uninitialized is left (or made) uninitialized on the double, so the
     * real body sees exactly what it would have seen on the donor — including
     * PHP's own "must not be accessed before initialization" for a property
     * nobody set.
     *
     * Each property is handled through a reflection against its **declaring**
     * class: before PHP 8.4 a readonly property can only be initialized from
     * the scope of the class that declares it, and a reflection reached
     * through the subclass writes from the subclass's scope.
     */
    public static function copyState(object $double, object $donor): void
    {
        for ($class = new \ReflectionClass($donor); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic() || $property->getDeclaringClass()->getName() !== $class->getName()) {
                    continue;
                }

                if (\PHP_VERSION_ID >= 80400 && self::propertyFlag($property, 'hasHooks')) {
                    // A virtual property has no state to copy; a backed one
                    // has its backing store, reachable without running the
                    // hooks — reading through them would execute the donor's
                    // code during what is test setup.
                    if (!self::propertyFlag($property, 'isVirtual') && $property->isInitialized($donor)) {
                        self::propertyMember($property, 'setRawValue', [
                            $double,
                            self::propertyMember($property, 'getRawValue', [$donor]),
                        ]);
                    }

                    continue;
                }

                if ($property->isInitialized($donor)) {
                    $property->setValue($double, $property->getValue($donor));

                    continue;
                }

                if ($property->isInitialized($double)) {
                    // The donor's truth is "uninitialized"; the double is
                    // holding one of the empty-scalar property defaults.
                    // Restore the donor's reading, or the real body would see
                    // a value the real object never had.
                    //
                    // No visibility dance and no `readonly` guard, because
                    // only one thing writes a property on a double the
                    // constructor skipped: `PropertyDefaults`, which writes
                    // public, writable, non-readonly properties and nothing
                    // else. That is exactly the set `unset()` from out here
                    // can reach, and the rest cannot be in this position.
                    unset($double->{$property->getName()});
                }
            }
        }
    }

    /**
     * Whether `$clone` is the copy PHP just took of `$frame`.
     *
     * Asked because `__clone()` is handed no reference to what it was cloned
     * from, and the running body's double is a guess: one generated class
     * serves every double of a type, so a body that was given another double
     * of its own class and cloned that one looks identical from here. What
     * separates them is the state, and this runs before anything could have
     * changed it — a generated `__clone()` only calls {@see adoptClone()},
     * never the doubled class's own `__clone()` body, so the copy still holds
     * exactly what it was copied from.
     *
     * `get_mangled_object_vars()` is the whole comparison, and it is the
     * right reading rather than a shortcut: it answers with the object's own
     * property table — private properties of every parent under their mangled
     * names, which a reflection of the double's class cannot reach at all —
     * and it answers without running anything. A hooked property comes back
     * as its backing store, not through its `get`, which is the doubled
     * class's code and must not run while `__clone()` is still deciding what
     * this copy is; a virtual property has no entry, because it has no state;
     * a static one belongs to the class both objects share; and a property
     * nobody initialized is absent, so "the donor never set this" differs
     * from any value at all. Two tables compare `===`: same keys in the same
     * order — a copy cannot reorder them — and the same values, which for an
     * object-valued property means the same object, as a copy holds.
     *
     * Two doubles in the same state stay indistinguishable, and that is the
     * residual of PHP's blind spot rather than something this can close: such
     * a copy is adopted and runs real bodies where a plain double would have
     * answered defaults. It runs them over the state the copy already holds,
     * so nothing reads a value the object never had — the readings are real,
     * they are simply the other double's.
     */
    private static function isCopyOf(object $clone, object $frame): bool
    {
        return get_mangled_object_vars($clone) === get_mangled_object_vars($frame);
    }

    /**
     * Asks a `ReflectionProperty` member that exists only from PHP 8.4.
     *
     * By name rather than as `$property->hasHooks()`, and the name arrives as
     * a parameter so that nothing can resolve it: Psalm reflects the real
     * `ReflectionProperty` of the PHP it runs on, so a direct call is an
     * `UndefinedMethod` under 8.3 — green on a machine with 8.5 and red in
     * the 8.3 matrix job, which is how this reached CI once. Same idiom as
     * `Codegen\PropertyDefaults::isWritableFromOutside()`.
     *
     * @param non-empty-string $member
     */
    private static function propertyFlag(\ReflectionProperty $property, string $member): bool
    {
        /** @var mixed $answer */
        $answer = $property->{$member}();

        return $answer === true;
    }

    /**
     * Calls an 8.4-only `ReflectionProperty` member, for the same reason.
     *
     * @param non-empty-string $member
     * @param list<mixed>      $args
     */
    private static function propertyMember(\ReflectionProperty $property, string $member, array $args): mixed
    {
        /** @var mixed $value */
        $value = $property->{$member}(...$args);

        return $value;
    }
}
