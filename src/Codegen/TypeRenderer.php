<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Codegen;

use Rasuvaeff\Understudy\Matcher\ArgumentMatcher;

/**
 * Renders Reflection types back into source.
 *
 * Parameters are widened with `ArgumentMatcher` by {@see TargetUnifier}, which
 * appends that branch exactly once after collecting the types of every target.
 * Widening is legal because PHP parameters are contravariant: an override may
 * accept more than the contract declares. It is what lets `Arg::any()` be
 * passed where the contract says `int`, while every other wrong type is still
 * rejected by PHP itself before the call reaches the dispatcher.
 *
 * @internal
 */
final class TypeRenderer
{
    public const string MATCHER = '\\' . ArgumentMatcher::class;

    private function __construct() {}

    /**
     * The contract's own parameter type, with `?T` expanded to `T|null` and
     * intersections parenthesised so a matcher branch can be appended in DNF.
     *
     * @return string empty when the contract declares no type, since there is
     *                then nothing to union a matcher onto
     */
    public static function parameterType(?\ReflectionType $type): string
    {
        if ($type === null) {
            return '';
        }

        if ($type instanceof \ReflectionIntersectionType) {
            return '(' . self::render($type) . ')';
        }

        if ($type instanceof \ReflectionNamedType && $type->allowsNull() && $type->getName() !== 'null') {
            $name = $type->getName();

            // `?Book` has to expand to `\Book|null`, keeping the leading
            // backslash: the generated class lives in its own namespace, and a
            // relative name would resolve to a class that does not exist.
            return $name === 'mixed' ? 'mixed' : self::qualify($type) . '|null';
        }

        return self::render($type);
    }

    /**
     * `mixed` and `object` already admit any matcher instance, so widening
     * them would only produce a redundant — and for `mixed`, illegal — union.
     */
    public static function acceptsMatcher(?\ReflectionType $type): bool
    {
        if (!$type instanceof \ReflectionNamedType) {
            return false;
        }

        return in_array($type->getName(), ['mixed', 'object'], strict: true);
    }

    /**
     * @return non-empty-string
     */
    public static function returnType(?\ReflectionType $type): string
    {
        return $type === null ? 'mixed' : self::render($type);
    }

    /**
     * @return non-empty-string
     */
    private static function render(\ReflectionType $type): string
    {
        if ($type instanceof \ReflectionUnionType) {
            return implode('|', array_map(
                static fn(\ReflectionType $part): string => $part instanceof \ReflectionIntersectionType
                    ? '(' . self::render($part) . ')'
                    : self::render($part),
                $type->getTypes(),
            ));
        }

        if ($type instanceof \ReflectionIntersectionType) {
            return implode('&', array_map(self::render(...), $type->getTypes()));
        }

        \assert($type instanceof \ReflectionNamedType);

        $name = $type->getName();
        $nullable = $type->allowsNull() && $name !== 'null' && $name !== 'mixed' ? '?' : '';

        return $nullable . self::qualify($type);
    }

    /**
     * A class name written into generated source must be absolute; a builtin
     * or a relative keyword must not be.
     *
     * @return non-empty-string
     */
    private static function qualify(\ReflectionNamedType $type): string
    {
        $name = $type->getName();

        return $type->isBuiltin() || in_array($name, ['self', 'static', 'parent'], strict: true)
            ? $name
            : '\\' . $name;
    }
}
