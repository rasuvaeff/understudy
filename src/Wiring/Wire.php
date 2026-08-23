<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Wiring;

use Rasuvaeff\Understudy\Exception\CannotWire;
use Rasuvaeff\Understudy\Understudy;

/**
 * Builds a real subject whose constructor dependencies are understudies.
 *
 * @psalm-type Argument = array|bool|float|int|object|string|null
 *
 * The scope is deliberately small: it reads the constructor and nothing else.
 * No container, no property injection, no setters — what a unit test needs to
 * see is the collaborators the class itself asks for.
 *
 * Every refusal happens before the constructor runs. A subject that was built
 * halfway and then failed would leave the test looking at a TypeError from
 * inside code it did not write.
 *
 * @internal
 */
final class Wire
{
    private function __construct() {}

    /**
     * @param class-string         $sut
     * @param array<string, mixed> $overrides
     *
     * @return array{sut: object, doubles: array<string, object>}
     */
    public static function build(string $sut, array $overrides): array
    {
        $reflection = self::reflectSubject($sut);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            if ($overrides !== []) {
                throw CannotWire::unknownOverride(
                    $sut,
                    array_key_first($overrides),
                    'nothing — it has no constructor',
                );
            }

            return ['sut' => $reflection->newInstance(), 'doubles' => []];
        }

        if (!$constructor->isPublic()) {
            throw CannotWire::inaccessibleConstructor($sut, $constructor->isPrivate() ? 'private' : 'protected');
        }

        $parameters = $constructor->getParameters();
        self::rejectUnknownOverrides($sut, $parameters, $overrides);

        /** @var array<string, Argument> $arguments */
        $arguments = [];
        /** @var array<string, object> $doubles */
        $doubles = [];

        foreach ($parameters as $parameter) {
            $name = $parameter->getName();

            if ($parameter->isPassedByReference()) {
                throw CannotWire::referenceParameter($sut, $name);
            }

            if (array_key_exists($name, $overrides)) {
                /** @var mixed $value */
                $value = $overrides[$name];
                self::rejectIncompatibleOverride($sut, $parameter, $value);
                $arguments[$name] = self::asArgument($sut, $name, $value);

                continue;
            }

            if ($parameter->isVariadic()) {
                // Nothing to spread: a variadic tail is by definition optional,
                // and inventing entries for it would invent collaborators.
                break;
            }

            $resolved = self::resolve($sut, $parameter);
            $arguments[$name] = $resolved[0];

            if ($resolved[1] !== null) {
                $doubles[$name] = $resolved[1];
            }
        }

        return ['sut' => $reflection->newInstanceArgs($arguments), 'doubles' => $doubles];
    }

    /**
     * @param class-string $sut
     *
     * @return \ReflectionClass<object>
     */
    private static function reflectSubject(string $sut): \ReflectionClass
    {
        /** @var class-string $sut */
        if (interface_exists($sut)) {
            throw CannotWire::notAConcreteClass($sut, 'it is an interface.');
        }

        if (trait_exists($sut)) {
            throw CannotWire::notAConcreteClass($sut, 'it is a trait.');
        }

        if (!class_exists($sut)) {
            throw CannotWire::notAConcreteClass($sut, 'there is no such class.');
        }

        $reflection = new \ReflectionClass($sut);

        if ($reflection->isEnum()) {
            throw CannotWire::notAConcreteClass($sut, 'it is an enum — its cases are the values themselves.');
        }

        if ($reflection->isAbstract()) {
            throw CannotWire::notAConcreteClass($sut, 'it is abstract, so it cannot be instantiated.');
        }

        return $reflection;
    }

    /**
     * @param class-string               $sut
     * @param list<\ReflectionParameter> $parameters
     * @param array<string, mixed>       $overrides
     */
    private static function rejectUnknownOverrides(string $sut, array $parameters, array $overrides): void
    {
        $names = array_map(static fn(\ReflectionParameter $p): string => $p->getName(), $parameters);

        foreach (array_keys($overrides) as $name) {
            if (!in_array($name, $names, strict: true)) {
                throw CannotWire::unknownOverride(
                    $sut,
                    $name,
                    implode(', ', array_map(static fn(string $n): string => '$' . $n, $names)),
                );
            }
        }
    }

    /**
     * @param class-string $sut
     */
    private static function rejectIncompatibleOverride(string $sut, \ReflectionParameter $parameter, mixed $value): void
    {
        $type = $parameter->getType();

        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            // Builtin and compound types are left to PHP's own coercion rules,
            // which are the ones the real caller would meet anyway.
            return;
        }

        $expected = $type->getName();

        if ($value === null && $type->allowsNull()) {
            return;
        }

        if ($value instanceof $expected) {
            return;
        }

        throw CannotWire::incompatibleOverride($sut, $parameter->getName(), $expected, get_debug_type($value));
    }

    /**
     * @param class-string $sut
     *
     * @return array{Argument, object|null} the argument, and the double if one was built for it
     */
    private static function resolve(string $sut, \ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();
        $contracts = $type === null ? [] : self::doublableContracts($type);

        if (count($contracts) === 1) {
            $double = Understudy::for($contracts[0]);

            return [$double, $double];
        }

        if (count($contracts) > 1 && $type instanceof \ReflectionIntersectionType) {
            $double = Understudy::for($contracts[0], ...array_slice($contracts, 1));

            return [$double, $double];
        }

        if (count($contracts) > 1) {
            throw CannotWire::undecidableParameter(
                $sut,
                $parameter->getName(),
                (string) $type,
                'the union names more than one object type, and picking one of them would be a guess.',
            );
        }

        if ($parameter->isDefaultValueAvailable()) {
            /** @var mixed $default */
            $default = $parameter->getDefaultValue();

            return [self::asArgument($sut, $parameter->getName(), $default), null];
        }

        if ($type !== null && $type->allowsNull()) {
            return [null, null];
        }

        throw CannotWire::undecidableParameter(
            $sut,
            $parameter->getName(),
            $type === null ? 'no type' : (string) $type,
            'it is not an object type and has no default value.',
        );
    }

    /**
     * Narrows a value to what a constructor argument can be.
     *
     * `mixed` is not a type this file can carry around honestly: everything
     * here ends up as an argument to `newInstanceArgs()`, and the one thing
     * that cannot be one is a resource. Naming the exception is better than
     * declaring `mixed` and letting it fail inside PHP's own call.
     *
     * @param class-string $sut
     *
     * @return Argument
     */
    private static function asArgument(string $sut, string $name, mixed $value): array|bool|float|int|object|string|null
    {
        if (\is_resource($value)) {
            throw CannotWire::undecidableParameter(
                $sut,
                $name,
                get_debug_type($value),
                'a resource cannot be passed as a constructor argument by wire().',
            );
        }

        /** @var Argument $value */
        return $value;
    }

    /**
     * The object types a parameter can hold, as contracts a double could stand
     * in for. `null` in a union is not one of them — it is the reason a
     * nullable object still gets a double rather than being passed `null`.
     *
     * @return list<class-string>
     */
    private static function doublableContracts(\ReflectionType $type): array
    {
        if ($type instanceof \ReflectionNamedType) {
            $name = $type->getName();

            return $type->isBuiltin() || !class_exists($name) && !interface_exists($name) ? [] : [$name];
        }

        if (!$type instanceof \ReflectionUnionType && !$type instanceof \ReflectionIntersectionType) {
            return [];
        }

        $contracts = [];

        foreach ($type->getTypes() as $branch) {
            $contracts = [...$contracts, ...self::doublableContracts($branch)];
        }

        return $contracts;
    }
}
