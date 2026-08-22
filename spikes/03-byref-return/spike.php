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

final class Runtime
{
    /** @var array<string, array<mixed>> keyed by object id + method */
    private static array $slots = [];

    public static function &dispatchReference(object $double, string $method): array
    {
        $key = spl_object_id($double) . ':' . $method;

        if (!array_key_exists($key, self::$slots)) {
            self::$slots[$key] = [];
        }

        return self::$slots[$key];
    }

    public static function peek(object $double, string $method): array
    {
        return self::$slots[spl_object_id($double) . ':' . $method] ?? [];
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
