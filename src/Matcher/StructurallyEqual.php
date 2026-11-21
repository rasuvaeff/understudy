<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Matcher;

use Rasuvaeff\Understudy\Expectation\ArgumentFormatter;

/**
 * Matches a value equal to the expected one by structure: an object of the
 * same class whose properties — private and protected included — are equal in
 * turn, an array with the same keys holding equal values, a scalar by `===`.
 *
 * `==` is not used. It compares scalars loosely (`'1' == 1`), and on a cyclic
 * graph it ends the process with a fatal "nesting level too deep" — from inside
 * the code under test, where a matcher must never break anything. A pair of
 * objects already being compared is taken as equal, which is what makes two
 * isomorphic cycles equal, and the walk stops at a fixed depth.
 *
 * Enum cases and closures compare by identity; a date by its instant and zone,
 * through `==`, which is safe there.
 *
 * @internal
 */
final readonly class StructurallyEqual implements ArgumentMatcher
{
    private const int MAX_DEPTH = 32;

    public function __construct(private mixed $expected) {}

    #[\Override]
    public function matches(mixed $argument): bool
    {
        $visiting = [];

        return self::equal($this->expected, $argument, 0, $visiting);
    }

    #[\Override]
    public function describe(): string
    {
        return 'equalTo(' . ArgumentFormatter::format($this->expected) . ')';
    }

    /**
     * @param array<string, true> $visiting
     */
    private static function equal(mixed $expected, mixed $actual, int $depth, array &$visiting): bool
    {
        // Not on arrays: `===` walks them, and a self-referencing array is
        // the same fatal `==` would be.
        if (!is_array($expected) && $expected === $actual) {
            return true;
        }

        if ($depth >= self::MAX_DEPTH) {
            return false;
        }

        if (is_array($expected) && is_array($actual)) {
            return self::equalArrays($expected, $actual, $depth, $visiting);
        }

        if (!is_object($expected) || !is_object($actual) || $expected::class !== $actual::class) {
            return false;
        }

        if ($expected instanceof \UnitEnum || $expected instanceof \Closure) {
            return false;
        }

        if ($expected instanceof \DateTimeInterface && $actual instanceof \DateTimeInterface) {
            return $expected == $actual
                && $expected->getTimezone()->getName() === $actual->getTimezone()->getName();
        }

        $pair = spl_object_id($expected) . ':' . spl_object_id($actual);

        if (isset($visiting[$pair])) {
            return true;
        }

        $visiting[$pair] = true;
        $equal = self::equalArrays(get_mangled_object_vars($expected), get_mangled_object_vars($actual), $depth, $visiting);
        unset($visiting[$pair]);

        return $equal;
    }

    /**
     * @param array<array-key, mixed> $expected
     * @param array<array-key, mixed> $actual
     * @param array<string, true>     $visiting
     */
    private static function equalArrays(array $expected, array $actual, int $depth, array &$visiting): bool
    {
        if (count($expected) !== count($actual)) {
            return false;
        }

        /** @var mixed $value */
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $actual) || !self::equal($value, $actual[$key], $depth + 1, $visiting)) {
                return false;
            }
        }

        return true;
    }
}
