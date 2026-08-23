<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Codegen;

/**
 * Decides which of a class target's public properties a double can safely
 * start with a value in, and which one.
 *
 * A double is built with the constructor skipped, so every typed property the
 * target would have initialized is left uninitialized and reading one raises
 * `Error: must not be accessed before initialization`. Filling the ones that
 * take a scalar, array or null turns the most common of those errors into the
 * empty value a test expects.
 *
 * What is deliberately left uninitialized: anything typed as an object or
 * intersection — a double has no business inventing one, and a real collaborator
 * or an accessor method is the honest answer; anything `readonly`, `final`, or
 * carrying property hooks or asymmetric visibility, because the language either
 * forbids the write or routes it through code the target expects to have run.
 *
 * @internal
 */
final class PropertyDefaults
{
    private function __construct() {}

    /**
     * @param \ReflectionClass<object> $target
     *
     * @return array<non-empty-string, mixed>
     */
    public static function forTarget(\ReflectionClass $target): array
    {
        $defaults = [];

        foreach ($target->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if (!self::isWritableFromOutside($property)) {
                continue;
            }

            $type = $property->getType();

            if ($type === null || $property->hasDefaultValue()) {
                // Untyped properties are already null, and a declared default
                // is applied by PHP itself before anything here runs.
                continue;
            }

            if (!self::hasDefault($type)) {
                continue;
            }

            $defaults[$property->getName()] = self::valueFor($type);
        }

        return $defaults;
    }

    private static function isWritableFromOutside(\ReflectionProperty $property): bool
    {
        if ($property->isStatic() || $property->isReadOnly()) {
            return false;
        }

        // PHP 8.4 members; on 8.3 no property can have them.
        foreach (['hasHooks', 'isFinal', 'isPrivateSet', 'isProtectedSet'] as $check) {
            if (method_exists($property, $check) && $property->{$check}()) {
                return false;
            }
        }

        return true;
    }

    private static function hasDefault(\ReflectionType $type): bool
    {
        if ($type->allowsNull()) {
            return true;
        }

        return $type instanceof \ReflectionNamedType
            && \in_array($type->getName(), ['int', 'float', 'string', 'bool', 'array', 'iterable', 'mixed'], strict: true);
    }

    /**
     * @return int|float|string|bool|array<never, never>|null
     */
    private static function valueFor(\ReflectionType $type): int|float|string|bool|array|null
    {
        if ($type->allowsNull()) {
            return null;
        }

        \assert($type instanceof \ReflectionNamedType);

        return match ($type->getName()) {
            'int' => 0,
            'float' => 0.0,
            'string' => '',
            'bool' => false,
            'array', 'iterable' => [],
            default => null,
        };
    }
}
