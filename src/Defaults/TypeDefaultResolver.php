<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Defaults;

use Rasuvaeff\Understudy\Codegen\MethodSignature;
use Rasuvaeff\Understudy\Exception\NoDefaultValue;
use Rasuvaeff\Understudy\Exception\UnsupportedTarget;
use Rasuvaeff\Understudy\Runtime\Runtime;
use Rasuvaeff\Understudy\Runtime\RuntimeContext;

/**
 * What a loose understudy answers when no expectation matched.
 *
 * The rule that shapes this table: never invent a value by running someone
 * else's constructor, and never hand back an object whose constructor was
 * skipped. Where no safe value exists, say so and name the way out.
 *
 * @internal
 */
final class TypeDefaultResolver
{
    private function __construct() {}

    /**
     * @param non-empty-string $label
     * @param non-empty-string $method
     */
    public static function forSignature(
        string $label,
        ?MethodSignature $signature,
        string $method,
        RuntimeContext $context,
        bool $nested = false,
    ): mixed {
        if ($signature === null) {
            return null;
        }

        return self::forType($label, $signature->returnType, $method, $context, $nested);
    }

    /**
     * The same table, asked for a rendered hooked property's type. An untyped
     * property answers `null`, which is also what PHP gives an untyped plain
     * one.
     *
     * @param non-empty-string $label
     * @param non-empty-string $member
     */
    public static function forPropertyType(
        string $label,
        ?string $type,
        string $member,
        RuntimeContext $context,
        bool $nested = false,
    ): mixed {
        if ($type === null || $type === '') {
            return null;
        }

        return self::forType($label, $type, $member, $context, $nested);
    }

    /**
     * @param non-empty-string $label
     * @param non-empty-string $method
     */
    private static function forType(
        string $label,
        string $type,
        string $method,
        RuntimeContext $context,
        bool $nested,
    ): mixed {
        if (str_starts_with($type, '?')) {
            // A registration is the test saying what this type should be, and
            // it means it here too. `null` is only the answer when nobody
            // said anything better.
            $registered = self::registered(substr($type, 1), $context);

            return $registered === null ? null : $registered[0];
        }

        // A union is satisfied by any one branch. Branches are compared
        // exactly, never as substrings: a class called `Nullable` is not a
        // `null` branch. `null` wins when present; otherwise the first branch
        // that has a safe default does, so `Book|string` answers with `''`
        // rather than failing on `Book`.
        if (str_contains($type, '|')) {
            $branches = self::branchesOf($type);

            if (in_array('null', $branches, strict: true)) {
                foreach ($branches as $branch) {
                    $registered = $branch === 'null' ? null : self::registered($branch, $context);

                    if ($registered !== null) {
                        return $registered[0];
                    }
                }

                return null;
            }

            // Two passes on purpose. A DNF intersection is object-shaped, and
            // the union rule prefers something scalar-safe: `(A&B)|string`
            // answers with `''`, as it did before intersections were
            // answerable at all. Only when no plain branch yields anything
            // does the first intersection get its turn — which is better than
            // refusing a type the engine can perfectly well build.
            $intersections = [];

            foreach ($branches as $branch) {
                if (str_contains($branch, '&')) {
                    $intersections[] = $branch;

                    continue;
                }

                try {
                    return self::forType($label, $branch, $method, $context, $nested);
                } catch (NoDefaultValue|UnsupportedTarget) {
                    continue;
                }
            }

            foreach ($intersections as $branch) {
                try {
                    return self::forType($label, $branch, $method, $context, $nested);
                } catch (NoDefaultValue|UnsupportedTarget) {
                    continue;
                }
            }

            throw NoDefaultValue::forReturnType($label, $method, $type);
        }

        if (str_contains($type, '&')) {
            $contracts = array_values(array_filter(
                array_map(
                    static fn(string $contract): string => ltrim(trim($contract), '\\'),
                    explode('&', $type),
                ),
                static fn(string $contract): bool => $contract !== '',
            ));

            if ($contracts === []) {
                throw NoDefaultValue::forReturnType($label, $method, $type);
            }

            /** @var non-empty-list<class-string> $contracts */
            return Runtime::adoptContractsInto($context, $contracts);
        }

        $name = ltrim($type, '\\');

        // A registered factory outranks everything below it, including the
        // builtin table: a test that said what a `Traversable` should be means
        // it for this type, not only for the classes the table has no answer
        // for.
        //
        // Asked only when something was registered. The check used to be
        // `class_exists($name)`, which autoloads — an autoloader round trip on
        // every unmatched call, for a name the table below usually answers
        // itself. It cost about half the dispatch time.
        $registered = self::registered($name, $context);

        if ($registered !== null) {
            return $registered[0];
        }

        return match ($name) {
            'void', 'null', 'mixed' => null,
            'bool', 'false' => false,
            'true' => true,
            'int' => 0,
            'float' => 0.0,
            'string' => '',
            'array', 'iterable' => [],
            'object' => new \stdClass(),
            'callable', 'Closure' => static fn(): null => null,
            'Generator' => self::emptyGenerator(),
            'Traversable', 'Iterator' => new \EmptyIterator(),
            'ArrayIterator' => new \ArrayIterator(),
            default => self::doubleOrFail($label, $name, $method, $context, $nested),
        };
    }

    /**
     * What the test registered for this type, if anything.
     *
     * Asked only when something was registered at all. The check used to be
     * `class_exists($name)`, which autoloads — an autoloader round trip on
     * every unmatched call, for a name the builtin table usually answers
     * itself. It cost about half the dispatch time.
     *
     * @return array{mixed}|null
     */
    private static function registered(string $type, RuntimeContext $context): ?array
    {
        $factories = $context->defaultFactories();

        if ($factories->isEmpty()) {
            return null;
        }

        $name = ltrim($type, '\\');

        if ($name === '') {
            return null;
        }

        /** @var class-string $name */
        return $factories->valueFor($name, $context);
    }

    /**
     * The last resort before failing: a doublable contract becomes a double of
     * its own, adopted into the context that owns the double being answered —
     * not whichever context happens to be current, which for a call made from
     * another Fiber would be the wrong one.
     *
     * Depth stops here, enforced rather than described: a double this method
     * created is marked, and asked for another one it refuses. One level keeps
     * a test moving; a chain of implicit collaborators is a design the test
     * should state out loud.
     *
     * @param non-empty-string $label
     * @param non-empty-string $method
     */
    private static function doubleOrFail(
        string $label,
        string $type,
        string $method,
        RuntimeContext $context,
        bool $nested,
    ): object {
        if ($nested) {
            // Depth stops at one, and it has to stop by refusing rather than by
            // documentation: a nested double answers from this same table, so
            // `$a->b()->c()` would otherwise keep inventing collaborators the
            // test never asked for and cannot see.
            throw NoDefaultValue::forReturnType($label, $method, $type);
        }

        if ((!class_exists($type) && !interface_exists($type)) || enum_exists($type)) {
            throw NoDefaultValue::forReturnType($label, $method, $type);
        }

        /** @var class-string $type */
        $reflection = new \ReflectionClass($type);

        if ($reflection->isFinal() || $reflection->isInternal()) {
            // Both are undoublable, and inventing an instance any other way
            // would mean running a constructor this engine knows nothing about.
            throw NoDefaultValue::forReturnType($label, $method, $type);
        }

        return Runtime::adoptInto($context, $type);
    }

    /**
     * `[]` would violate a declared `: Generator`, so the empty case has to be
     * an actual generator.
     */
    private static function emptyGenerator(): \Generator
    {
        return (static function (): \Generator {
            yield from [];
        })();
    }

    /**
     * Splits a union while leaving a DNF intersection intact, so `(A&B)|null`
     * yields `(A&B)` and `null`.
     *
     * @return list<non-empty-string>
     */
    private static function branchesOf(string $type): array
    {
        $branches = [];
        $depth = 0;
        $buffer = '';

        foreach (str_split($type) as $character) {
            $depth += match ($character) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };

            if ($character === '|' && $depth === 0) {
                $branches[] = $buffer;
                $buffer = '';

                continue;
            }

            $buffer .= $character;
        }

        $branches[] = $buffer;

        return array_values(array_filter(
            array_map(self::stripIntersectionParentheses(...), $branches),
            static fn(string $branch): bool => $branch !== '',
        ));
    }

    /**
     * Reflection renders a DNF intersection as `(A&B)` when it is a union
     * branch. Unwrapping happens per branch, after the split: applied to a
     * whole type it would cut `(A&B)|(C&D)` in half — that string also starts
     * with `(` and ends with `)` — and the halves would then never split,
     * because the `|` sits at a parenthesis depth the parser reads as -1.
     */
    private static function stripIntersectionParentheses(string $type): string
    {
        return str_starts_with($type, '(') && str_ends_with($type, ')')
            ? substr($type, 1, -1)
            : $type;
    }
}
