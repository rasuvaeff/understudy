<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Wiring;

use Rasuvaeff\Understudy\Exception\CannotWire;
use Rasuvaeff\Understudy\Exception\UnsupportedTarget;
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

        foreach ($parameters as $position => $parameter) {
            $name = $parameter->getName();

            if ($parameter->isPassedByReference()) {
                throw CannotWire::referenceParameter($sut, $name);
            }

            if (array_key_exists($name, $overrides)) {
                /** @var mixed $value */
                $value = $overrides[$name];

                if ($parameter->isVariadic()) {
                    self::rejectVariadicOverride($sut, $parameter, $value);
                    /** @var list<Argument> $value */
                    $arguments = self::withVariadicTail(
                        $sut,
                        array_slice($parameters, 0, $position),
                        $arguments,
                        $value,
                        $name,
                    );
                    break;
                }

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

            if ($resolved === null) {
                // Omitted on purpose: PHP applies the constructor's own default
                // when the named argument is absent. Reading it here would
                // evaluate it, and `= new Foo()` is a legal default whose
                // constructor has no business running during wiring.
                continue;
            }

            $arguments[$name] = $resolved[0];

            if ($resolved[1] !== null) {
                $doubles[$name] = $resolved[1];
            }
        }

        return ['sut' => $reflection->newInstanceArgs($arguments), 'doubles' => $doubles];
    }

    /**
     * Reflection forbids positional arguments after named ones. A variadic
     * override therefore needs a positional prefix, including any omitted
     * optional parameter before the tail.
     *
     * @param class-string                         $sut
     * @param list<\ReflectionParameter>           $prefix
     * @param array<string, Argument>              $resolved
     * @param list<Argument>                       $tail
     * @param non-empty-string                     $tailName
     *
     * @return list<Argument>
     */
    private static function withVariadicTail(
        string $sut,
        array $prefix,
        array $resolved,
        array $tail,
        string $tailName,
    ): array {
        $arguments = [];

        foreach ($prefix as $parameter) {
            $name = $parameter->getName();

            if (array_key_exists($name, $resolved)) {
                $arguments[] = $resolved[$name];

                continue;
            }

            // This is an omitted optional before the variadic tail. Passing a
            // later positional value requires materializing its declared
            // default, just as an ordinary caller would have to do.
            $arguments[] = self::asArgument($sut, $name, $parameter->getDefaultValue());
        }

        /** @var list<Argument> $tailValues */
        $tailValues = $tail;

        foreach ($tailValues as $item) {
            $arguments[] = self::asArgument($sut, $tailName, $item);
        }

        return $arguments;
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

        if ($type === null || ($type instanceof \ReflectionNamedType && $type->isBuiltin())) {
            // Builtin types are left to PHP's own coercion rules, which are
            // the ones the real caller would meet anyway.
            return;
        }

        if (self::matchesType($type, $value)) {
            return;
        }

        throw CannotWire::incompatibleOverride($sut, $parameter->getName(), (string) $type, get_debug_type($value));
    }

    /**
     * @param class-string $sut
     */
    private static function rejectVariadicOverride(string $sut, \ReflectionParameter $parameter, mixed $value): void
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw CannotWire::incompatibleOverride($sut, $parameter->getName(), 'list<' . self::typeName($parameter->getType()) . '>', get_debug_type($value));
        }

        /** @var list<Argument> $value */

        $type = $parameter->getType();

        /** @var list<Argument> $items */
        $items = $value;

        foreach ($items as $item) {
            if ($type !== null && !self::matchesType($type, $item)) {
                throw CannotWire::incompatibleOverride($sut, $parameter->getName(), 'list<' . self::typeName($type) . '>', get_debug_type($item));
            }
        }
    }

    private static function matchesType(\ReflectionType $type, mixed $value): bool
    {
        if ($value === null) {
            return $type->allowsNull();
        }

        if ($type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $branch) {
                if (self::matchesType($branch, $value)) {
                    return true;
                }
            }

            return false;
        }

        if ($type instanceof \ReflectionIntersectionType) {
            foreach ($type->getTypes() as $branch) {
                if (!self::matchesType($branch, $value)) {
                    return false;
                }
            }

            return true;
        }

        \assert($type instanceof \ReflectionNamedType);

        $name = $type->getName();

        return match ($name) {
            'bool' => is_bool($value),
            'true' => $value === true,
            'false' => $value === false,
            'int' => is_int($value),
            'float' => is_float($value),
            'string' => is_string($value),
            'array' => is_array($value),
            'object' => is_object($value),
            'callable' => is_callable($value),
            'iterable' => is_iterable($value),
            'mixed' => true,
            default => $value instanceof $name,
        };
    }

    private static function typeName(?\ReflectionType $type): string
    {
        return $type === null ? 'mixed' : (string) $type;
    }

    /**
     * @param class-string $sut
     *
     * @return array{Argument, object|null}|null the argument and the double built
     *                                            for it, or null when the parameter
     *                                            should be left to its own default
     */
    private static function resolve(string $sut, \ReflectionParameter $parameter): ?array
    {
        $type = $parameter->getType();
        $contracts = $type === null ? [] : self::doublableContracts($type);

        if (count($contracts) === 1) {
            return self::doubleOrDefault($parameter, $contracts);
        }

        if (count($contracts) > 1 && $type instanceof \ReflectionIntersectionType) {
            return self::doubleOrDefault($parameter, $contracts);
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
            return null;
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
     * A double of the contracts, or nothing when the parameter can keep its own
     * default instead.
     *
     * An object parameter is a collaborator, so a double is what the test
     * wants. Some of them cannot be doubled — a final class most often — and a
     * parameter that already says what to use in that case is answered by its
     * own default rather than by refusing the whole subject. Doublability is
     * decided by trying, so that this file does not grow a second copy of the
     * rules `DoubleFactory` already owns.
     *
     * @param non-empty-list<class-string> $contracts
     *
     * @return array{Argument, object|null}|null
     */
    private static function doubleOrDefault(\ReflectionParameter $parameter, array $contracts): ?array
    {
        try {
            $double = Understudy::for($contracts[0], ...array_slice($contracts, 1));
        } catch (UnsupportedTarget $undoublable) {
            if ($parameter->isDefaultValueAvailable()) {
                return null;
            }

            throw $undoublable;
        }

        return [$double, $double];
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
