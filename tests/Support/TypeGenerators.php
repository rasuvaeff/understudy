<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Support;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\Understudy\Codegen\DnfType;

/**
 * Types and values for the type-model laws: atoms with real subtyping between
 * them — a class hierarchy, the pseudo-types that widen builtins — so that
 * `satisfies` has something to say beyond equality.
 */
final class TypeGenerators
{
    /** @var non-empty-list<non-empty-string> */
    private const array ATOMS = [
        'int', 'float', 'string', 'bool', 'true', 'false', 'null', 'array', 'iterable',
        'callable', 'object', 'mixed', 'never',
        '\\Traversable', '\\Iterator', '\\IteratorAggregate', '\\Countable', '\\ArrayAccess',
        '\\ArrayIterator', '\\ArrayObject', '\\Closure', '\\Stringable', '\\stdClass',
    ];

    /**
     * The unifier's markers. A rendered type cannot carry them, and the
     * generated `static` is not transitive through a target's `self` (see
     * `DnfTypePropertyTest::satisfiesIsTransitive()`), so they are opt-in.
     *
     * @var non-empty-list<non-empty-string>
     */
    private const array MARKERS = [
        DnfType::GENERATED_STATIC,
        DnfType::TARGET_SELF . 'Countable',
    ];

    /**
     * Types in DNF built from the atom pool: one to three branches of one to
     * three atoms.
     */
    public static function dnfType(): ArbitraryInterface
    {
        return self::dnfOver([...self::ATOMS, ...self::MARKERS]);
    }

    /**
     * The same, over atoms a rendered type can spell.
     */
    public static function renderableDnfType(): ArbitraryInterface
    {
        return self::dnfOver(self::ATOMS);
    }

    /**
     * @param non-empty-list<non-empty-string> $atoms
     */
    private static function dnfOver(array $atoms): ArbitraryInterface
    {
        $branch = Gen::uniqueArrayOf(Gen::elements($atoms), minSize: 1, maxSize: 3);

        return Gen::map(
            Gen::uniqueArrayOf($branch, minSize: 1, maxSize: 3, by: static fn(array $atoms): string => implode('&', $atoms)),
            static fn(array $branches): DnfType => DnfType::of(array_values(array_map(array_values(...), $branches))),
        );
    }

    /**
     * Two atoms an intersection can join: anything object-shaped.
     */
    public static function objectAtom(): ArbitraryInterface
    {
        return Gen::elements([
            '\\Traversable', '\\Iterator', '\\IteratorAggregate', '\\Countable', '\\ArrayAccess',
            '\\ArrayIterator', '\\Stringable', 'object',
        ]);
    }

    /**
     * Return types PHP can declare on a closure, as `TypeRenderer` renders
     * them: absolute class names, `?T`, unions, a DNF group.
     */
    public static function declarableReturnType(): ArbitraryInterface
    {
        return Gen::elements([
            'int', 'float', 'string', 'bool', 'true', 'false', 'array', 'iterable', 'callable',
            'object', 'mixed', '?int', '?string', '?\\Countable', 'int|string', 'int|float',
            'string|bool', 'array|false', 'iterable|null', '\\Countable', '\\Traversable',
            '\\ArrayIterator', '\\Stringable', '\\Closure', '\\stdClass', '\\Countable&\\Traversable',
            '(\\Countable&\\Traversable)|null', '(\\Countable&\\Traversable)|string', '\\Stringable|int',
        ]);
    }

    /**
     * Values of every shape a `returns()` is handed.
     */
    public static function returnedValue(): ArbitraryInterface
    {
        return Gen::frequency([
            [3, Gen::elements([0, 1, -7, 42])],
            [2, Gen::elements([0.0, 1.5, -2.25])],
            [3, Gen::elements(['', '5', 'abc', '1.5'])],
            [2, Gen::elements([true, false])],
            [2, Gen::elements([null])],
            [2, Gen::elements([[], [1, 2]])],
            [4, Gen::map(Gen::elements(['iterator', 'object', 'closure', 'stringable', 'std', 'countable']), self::object(...))],
        ]);
    }

    private static function object(string $kind): object
    {
        return match ($kind) {
            'iterator' => new \ArrayIterator([1]),
            'object' => new \ArrayObject([]),
            'closure' => static fn(): int => 1,
            'stringable' => new class implements \Stringable {
                #[\Override]
                public function __toString(): string
                {
                    return 'text';
                }
            },
            'countable' => new class implements \Countable {
                #[\Override]
                public function count(): int
                {
                    return 0;
                }
            },
            default => new \stdClass(),
        };
    }
}
