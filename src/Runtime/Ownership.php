<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Runtime;

use Rasuvaeff\Understudy\Exception\ForgottenDouble;

/**
 * Which context owns each double, and which doubles were forgotten — and by
 * what: a reset, a closing scope, or `Understudy::forget()`.
 *
 * @internal
 */
final class Ownership
{
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
     * The forgotten doubles a closing `scope()` dropped, as opposed to a
     * `reset()`. Only the failure message tells them apart: "created before a
     * reset()" sends the reader looking for a reset() they never wrote when
     * what ended the double was the scope it was built in.
     *
     * @var \WeakMap<object, true>|null
     */
    private static ?\WeakMap $droppedByScope = null;

    /**
     * Doubles retired on purpose through `Understudy::forget()`. The
     * distinction is for the failure message only: both a reset and a
     * deliberate retirement make the double unusable, but telling a reader
     * to look for a reset() they never wrote is worse than the plain truth.
     *
     * @var \WeakMap<object, true>|null
     */
    private static ?\WeakMap $retiredOnPurpose = null;

    private function __construct() {}

    /**
     * The owner map itself, so that registering a double costs what it did
     * before ownership had a class of its own: one call and a write.
     *
     * @return \WeakMap<object, RuntimeContext>
     */
    public static function owners(): \WeakMap
    {
        if (!self::$owners instanceof \WeakMap) {
            /** @var \WeakMap<object, RuntimeContext> $owners */
            $owners = new \WeakMap();
            self::$owners = $owners;
        }

        return self::$owners;
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
     * Whether a double was forgotten by the `scope()` it was created in
     * closing, rather than by a `reset()`.
     */
    public static function isDroppedByScope(object $double): bool
    {
        return self::$droppedByScope?->offsetExists($double) ?? false;
    }

    /**
     * Whether a double was retired on purpose through `Understudy::forget()`.
     */
    public static function isRetiredOnPurpose(object $double): bool
    {
        return self::$retiredOnPurpose?->offsetExists($double) ?? false;
    }

    /**
     * The refusal for a call or a question that reached a forgotten double,
     * worded by what forgot it: `forget()`, a closing scope, or a reset.
     *
     * @param non-empty-string $member
     */
    public static function forgottenDouble(object $double, string $member): ForgottenDouble
    {
        if (self::isRetiredOnPurpose($double)) {
            return ForgottenDouble::onPurpose($member);
        }

        if (self::isDroppedByScope($double)) {
            return ForgottenDouble::afterScope($member);
        }

        return ForgottenDouble::afterReset($member);
    }

    /**
     * The refusal for a property touched on a forgotten double, worded the
     * same way as `forgottenDouble()`.
     *
     * @param non-empty-string $property
     */
    public static function forgottenProperty(object $double, string $property): ForgottenDouble
    {
        if (self::isRetiredOnPurpose($double)) {
            return ForgottenDouble::propertyOnPurpose($property);
        }

        if (self::isDroppedByScope($double)) {
            return ForgottenDouble::propertyAfterScope($property);
        }

        return ForgottenDouble::propertyAfterReset($property);
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

        // The facade has already answered for a stranger, a retired double and
        // a foreign context through `stateOf()`; what reaches here is owned.
        \assert($owner instanceof \Rasuvaeff\Understudy\Runtime\RuntimeContext && $owner->stateOf($double) instanceof \Rasuvaeff\Understudy\Runtime\DoubleState);

        $owner->forget($double);
        self::owners()->offsetUnset($double);
        self::forgotten()->offsetSet($double, value: true);

        if (!self::$retiredOnPurpose instanceof \WeakMap) {
            /** @var \WeakMap<object, true> $retired */
            $retired = new \WeakMap();
            self::$retiredOnPurpose = $retired;
        }

        self::$retiredOnPurpose->offsetSet($double, value: true);
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
    public static function retire(
        RuntimeContext $context,
        bool $byScope = false,
    ): void {
        if (!self::$owners instanceof \WeakMap) {
            return;
        }

        foreach ($context->allDoubles() as $double) {
            self::forgotten()[$double] = true;
            unset(self::$owners[$double]);

            if ($byScope) {
                self::droppedByScope()[$double] = true;
            }
        }
    }

    /**
     * @return \WeakMap<object, true>
     */
    private static function droppedByScope(): \WeakMap
    {
        if (!self::$droppedByScope instanceof \WeakMap) {
            /** @var \WeakMap<object, true> $dropped */
            $dropped = new \WeakMap();
            self::$droppedByScope = $dropped;
        }

        return self::$droppedByScope;
    }

    /**
     * @return \WeakMap<object, true>
     */
    private static function forgotten(): \WeakMap
    {
        if (!self::$forgotten instanceof \WeakMap) {
            /** @var \WeakMap<object, true> $forgotten */
            $forgotten = new \WeakMap();
            self::$forgotten = $forgotten;
        }

        return self::$forgotten;
    }
}
