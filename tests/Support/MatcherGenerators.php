<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Support;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;

/**
 * Generators shared by the matcher properties.
 *
 * They live here rather than as private static helpers in the test class:
 * Rector's LocallyCalledStaticMethodToNonStaticRector converts such a helper
 * into an instance method as soon as a property body calls it, and a public
 * static `*Generators()` method then cannot reach it. That failure surfaces on
 * `composer release-check`, not on `composer build`.
 */
final class MatcherGenerators
{
    private function __construct() {}

    /**
     * Choosing among generators is `frequency`, not `oneOf`: `oneOf` picks
     * among the VALUES it is given, so handing it generators would make the
     * generators themselves the test data — silently, for a `mixed` parameter.
     */
    public static function anyScalar(): ArbitraryInterface
    {
        return Gen::frequency([
            [1, Gen::int()],
            [1, Gen::stringAscii()],
            [1, Gen::bool()],
            [1, Gen::float()],
        ]);
    }

    /**
     * Ints alongside the near-misses a strict matcher must reject: a numeric
     * string, a float, a boolean.
     */
    public static function intWithNearMisses(): ArbitraryInterface
    {
        return Gen::frequency([
            [2, Gen::int()],
            [1, Gen::map(Gen::int(), static fn(int $n): string => (string) $n)],
            [1, Gen::float()],
            [1, Gen::bool()],
        ]);
    }

    /**
     * Anything at all, including `null` and arrays.
     */
    public static function anyValue(): ArbitraryInterface
    {
        return Gen::frequency([
            [1, Gen::int()],
            [1, Gen::stringAscii()],
            [1, Gen::bool()],
            [1, Gen::float()],
            [1, Gen::arrayOf(Gen::int(), 0, 4)],
            [1, Gen::nullable(Gen::int())],
        ]);
    }
}
