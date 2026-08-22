<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Defaults;

use Rasuvaeff\Understudy\Codegen\MethodSignature;
use Rasuvaeff\Understudy\Exception\NoDefaultValue;

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
    public static function forSignature(string $label, ?MethodSignature $signature, string $method): mixed
    {
        if ($signature === null) {
            return null;
        }

        return self::forType($label, $signature->returnType, $method);
    }

    /**
     * @param non-empty-string $label
     * @param non-empty-string $method
     */
    private static function forType(string $label, string $type, string $method): mixed
    {
        if (str_starts_with($type, '?')) {
            return null;
        }

        // A union is satisfied by any one branch. Branches are compared
        // exactly, never as substrings: a class called `Nullable` is not a
        // `null` branch. `null` wins when present; otherwise the first branch
        // that has a safe default does, so `Book|string` answers with `''`
        // rather than failing on `Book`.
        if (str_contains($type, '|')) {
            $branches = self::branchesOf($type);

            if (in_array('null', $branches, strict: true)) {
                return null;
            }

            foreach ($branches as $branch) {
                try {
                    return self::forType($label, $branch, $method);
                } catch (NoDefaultValue) {
                    continue;
                }
            }

            throw NoDefaultValue::forReturnType($label, $method, $type);
        }

        return match (ltrim($type, '\\')) {
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
            default => throw NoDefaultValue::forReturnType($label, $method, $type),
        };
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
            $branches,
            static fn(string $branch): bool => $branch !== '',
        ));
    }
}
