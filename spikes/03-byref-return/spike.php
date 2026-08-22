<?php

/**
 * Spike 03 — by-reference return through a dispatch layer.
 *
 * Promises under test (plan §5.1):
 * - an interface method declared `function &ref(): array` can be overridden
 *   by a double whose implementation returns a reference to a stable slot
 *   held OUTSIDE the double (doubles own no state);
 * - mutations made through the obtained reference are visible on the next read;
 * - a chain double -> runtime helper keeps the reference alive (the helper
 *   itself must be declared `&dispatchReference`).
 */

declare(strict_types=1);

namespace Understudy\Spikes\ByRefReturn;

use function Understudy\Spikes\assertSame;
use function Understudy\Spikes\assertTrue;

require __DIR__ . '/../lib.php';

interface Registry
{
    public function &values(): array;
}

/**
 * A WeakMap value cannot be modified by reference directly, so each double
 * owns a holder object whose property the reference points into.
 */
final class SlotHolder
{
    /** @var array<string, array<mixed>> */
    public array $slots = [];
}

final class Runtime
{
    /**
     * Keyed by the double OBJECT, never by `spl_object_id()`: PHP reuses an
     * object id after collection, so an id-keyed store would hand a fresh
     * double the previous one's slot (plan §5.2 — SplObjectStorage/WeakMap).
     *
     * @var \WeakMap<object, SlotHolder>|null
     */
    private static ?\WeakMap $slots = null;

    public static function &dispatchReference(object $double, string $method): array
    {
        self::$slots ??= new \WeakMap();

        if (!isset(self::$slots[$double])) {
            self::$slots[$double] = new SlotHolder();
        }

        $holder = self::$slots[$double];

        if (!array_key_exists($method, $holder->slots)) {
            $holder->slots[$method] = [];
        }

        return $holder->slots[$method];
    }

    public static function peek(object $double, string $method): array
    {
        if (self::$slots === null || !isset(self::$slots[$double])) {
            return [];
        }

        return self::$slots[$double]->slots[$method] ?? [];
    }

    public static function trackedDoubles(): int
    {
        return count(self::$slots ?? []);
    }
}

final class RegistryDouble implements Registry
{
    public function &values(): array
    {
        $ref = &Runtime::dispatchReference($this, __FUNCTION__);

        return $ref;
    }
}

echo "03-byref-return\n";

$double = new RegistryDouble();

$values = &$double->values();
$values['a'] = 1;

assertSame(Runtime::peek($double, 'values'), ['a' => 1], 'mutation through the returned reference reaches the external slot');

$again = &$double->values();
assertSame($again, ['a' => 1], 'second by-ref call sees the same stable slot');

$again['b'] = 2;
assertSame($values, ['a' => 1, 'b' => 2], 'both references alias one slot');

$other = new RegistryDouble();
$otherValues = &$other->values();
assertTrue($otherValues === [], 'slots are per-double, not shared');

$copy = $double->values();
$copy['c'] = 3;
assertSame(Runtime::peek($double, 'values'), ['a' => 1, 'b' => 2], 'plain (non-reference) read copies and cannot corrupt the slot');

// Weak keying: a collected double takes its slot with it, so a later double
// that happens to reuse its object id can never inherit stale values.
$tracked = Runtime::trackedDoubles();
$temporary = new RegistryDouble();
$temporarySlot = &$temporary->values();
$temporarySlot['stale'] = true;

assertSame(Runtime::trackedDoubles(), $tracked + 1, 'a new double adds one weakly-held entry');

unset($temporary, $temporarySlot);
gc_collect_cycles();

assertSame(Runtime::trackedDoubles(), $tracked, 'the entry is released once the double is collected');
