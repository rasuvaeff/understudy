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

        // A union is satisfied by any one branch, and `null` is the safest
        // branch there is.
        if (str_contains($type, '|')) {
            return str_contains($type, 'null') ? null : self::forType(
                $label,
                self::firstBranch($type),
                $method,
            );
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
     * @return non-empty-string
     */
    private static function firstBranch(string $type): string
    {
        $branch = explode('|', $type)[0];
        \assert($branch !== '');

        return $branch;
    }
}
