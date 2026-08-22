<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Codegen;

use Rasuvaeff\Understudy\Exception\UnsupportedTarget;

/**
 * Turns one or more contracts into the signatures a single class can declare.
 *
 * Same-named methods across targets are UNIFIED, not compared for equality.
 * PHP parameters are contravariant, so `write(int)` and `write(string)` share
 * a valid implementation — `write(int|string)` — and rejecting them because
 * their signatures differ would refuse a pair that is perfectly doublable. A
 * conflict is reported only where no single declaration can satisfy every
 * target: return types (which are covariant) that disagree, and a parameter
 * that is by-reference in one target and by-value in another.
 *
 * @internal
 */
final class TargetUnifier
{
    private function __construct() {}

    /**
     * @param non-empty-list<\ReflectionClass<object>> $targets
     *
     * @return array<non-empty-string, MethodSignature>
     */
    public static function unify(array $targets): array
    {
        /** @var array<non-empty-string, list<\ReflectionMethod>> $byName */
        $byName = [];

        foreach ($targets as $target) {
            foreach ($target->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic()) {
                    // Static methods carry no instance state; there is nothing
                    // for an instance double to intercept.
                    continue;
                }

                $byName[$method->getName()][] = $method;
            }
        }

        $signatures = [];

        foreach ($byName as $name => $declarations) {
            \assert($declarations !== []);

            $signatures[$name] = self::unifyMethod($name, $declarations);
        }

        return $signatures;
    }

    /**
     * @param non-empty-string              $name
     * @param non-empty-list<\ReflectionMethod> $declarations
     */
    private static function unifyMethod(string $name, array $declarations): MethodSignature
    {
        $returnType = self::unifyReturnType($name, $declarations);
        $arity = max(array_map(
            static fn(\ReflectionMethod $method): int => $method->getNumberOfParameters(),
            $declarations,
        ));

        $parameters = [];
        $arguments = [];

        for ($position = 0; $position < $arity; $position++) {
            $parameter = self::unifyParameter($name, $declarations, $position);
            $parameters[] = $parameter['rendered'];

            // Collected by name rather than with func_get_args(), which omits
            // parameters the caller left at their default: the call log must
            // show the arguments the method actually received, so that
            // `tag('alpha')` and `tag('alpha', 1)` verify as the same call.
            $arguments[] = ($parameter['variadic'] ? '...$' : '$') . $parameter['name'];
        }

        $reference = $declarations[0]->returnsReference();

        foreach ($declarations as $declaration) {
            if ($declaration->returnsReference() !== $reference) {
                throw UnsupportedTarget::signatureConflict(
                    $name,
                    self::describe($declarations[0]) . ($reference ? ' returns by reference' : ' returns by value'),
                    self::describe($declaration) . ($reference ? ' returns by value' : ' returns by reference'),
                );
            }
        }

        return new MethodSignature(
            name: $name,
            parameters: implode(', ', $parameters),
            arguments: '[' . implode(', ', $arguments) . ']',
            returnType: $returnType,
            returnsNever: $returnType === 'never',
            returnsVoid: $returnType === 'void',
            returnsReference: $reference,
        );
    }

    /**
     * Return types are covariant, so an implementation must satisfy all of
     * them at once. `int` and `string` have no common subtype: no class can
     * implement both, and PHP rejects the attempt at compile time.
     *
     * @param non-empty-string              $name
     * @param non-empty-list<\ReflectionMethod> $declarations
     *
     * @return non-empty-string
     */
    private static function unifyReturnType(string $name, array $declarations): string
    {
        /** @var array<non-empty-string, \ReflectionMethod> $seen */
        $seen = [];

        foreach ($declarations as $declaration) {
            $seen[TypeRenderer::returnType($declaration->getReturnType())] ??= $declaration;
        }

        if (count($seen) > 1) {
            $types = array_keys($seen);
            $methods = array_values($seen);

            throw UnsupportedTarget::signatureConflict(
                $name,
                self::describe($methods[0]) . ' declares `: ' . $types[0] . '`',
                self::describe($methods[1]) . ' declares `: ' . $types[1] . '`',
            );
        }

        return array_key_first($seen);
    }

    /**
     * @param non-empty-string              $name
     * @param non-empty-list<\ReflectionMethod> $declarations
     *
     * @return array{rendered: non-empty-string, name: non-empty-string, variadic: bool}
     */
    private static function unifyParameter(string $name, array $declarations, int $position): array
    {
        /** @var array<string, true> $types */
        $types = [];
        $byReference = null;
        $variadic = false;
        $declaredEverywhere = true;
        $requiredEverywhere = true;
        $parameterName = 'p' . $position;
        $untyped = false;
        $acceptsMatcher = true;
        $declaredDefaults = [];

        foreach ($declarations as $declaration) {
            $parameter = $declaration->getParameters()[$position] ?? null;

            if ($parameter === null) {
                $declaredEverywhere = false;

                continue;
            }

            $rendered = TypeRenderer::parameterType($parameter->getType());

            if ($rendered === '') {
                $untyped = true;
            } else {
                foreach (self::splitUnion($rendered) as $part) {
                    $types[$part] = true;
                }
            }

            $acceptsMatcher = $acceptsMatcher && TypeRenderer::acceptsMatcher($parameter->getType());

            if ($byReference !== null && $byReference !== $parameter->isPassedByReference()) {
                throw UnsupportedTarget::signatureConflict(
                    $name,
                    self::describe($declarations[0]) . ' takes parameter #' . ($position + 1) . ' by ' . ($byReference ? 'reference' : 'value'),
                    self::describe($declaration) . ' takes it by ' . ($parameter->isPassedByReference() ? 'reference' : 'value'),
                );
            }

            $byReference = $parameter->isPassedByReference();
            $variadic = $variadic || $parameter->isVariadic();
            $requiredEverywhere = $requiredEverywhere && !$parameter->isOptional();
            $parameterName = $parameter->getName();

            if (!$parameter->isVariadic() && $parameter->isDefaultValueAvailable()) {
                $declaredDefaults[self::renderDefault($parameter->getDefaultValue())] = true;
            }
        }

        // An untyped parameter in any target means the union cannot be
        // expressed at all — every value is already allowed.
        //
        // The matcher branch is appended here, once, rather than by each
        // target's rendering: otherwise unifying `write(int)` with
        // `write(string)` would interleave it as `int|Matcher|string`.
        if ($untyped) {
            $type = '';
        } else {
            if (!$acceptsMatcher) {
                $types[TypeRenderer::MATCHER] = true;
            }

            $type = implode('|', array_keys($types));
        }

        if ($variadic) {
            // A variadic tail is optional by nature and cannot carry a default.
            $rendered = trim($type . ' ' . ($byReference === true ? '&' : '') . '...$' . $parameterName);
            \assert($rendered !== '');

            return ['rendered' => $rendered, 'name' => $parameterName, 'variadic' => true];
        }

        $optional = !$declaredEverywhere || !$requiredEverywhere;

        // Keeping the contract's own default is what makes an omitted argument
        // observable: `tag('alpha')` must log the same arguments as
        // `tag('alpha', 1)`, or the two would verify as different calls.
        $default = count($declaredDefaults) === 1 ? array_key_first($declaredDefaults) : 'null';

        if ($optional && $default === 'null' && $type !== '' && !str_contains($type, 'null')) {
            // `= null` on a non-nullable type is an implicitly nullable
            // parameter, deprecated since 8.4 — widen the type instead.
            $type .= '|null';
        }

        $rendered = trim(sprintf(
            '%s %s$%s%s',
            $type,
            $byReference === true ? '&' : '',
            $parameterName,
            $optional ? ' = ' . $default : '',
        ));
        \assert($rendered !== '');

        return ['rendered' => $rendered, 'name' => $parameterName, 'variadic' => false];
    }

    /**
     * Splits a rendered union while keeping DNF parentheses intact, so
     * `(A&B)|null` yields `(A&B)` and `null` rather than four fragments.
     *
     * @return list<string>
     */
    private static function splitUnion(string $rendered): array
    {
        if (str_starts_with($rendered, '?')) {
            return [substr($rendered, 1), 'null'];
        }

        $parts = [];
        $depth = 0;
        $buffer = '';

        foreach (str_split($rendered) as $character) {
            if ($character === '(') {
                $depth++;
            }

            if ($character === ')') {
                $depth--;
            }

            if ($character === '|' && $depth === 0) {
                $parts[] = $buffer;
                $buffer = '';

                continue;
            }

            $buffer .= $character;
        }

        $parts[] = $buffer;

        return array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
    }

    /**
     * Only values that round-trip through source are kept; anything else falls
     * back to `null`, which is always a legal default once the type is widened.
     *
     * @return non-empty-string
     */
    private static function renderDefault(mixed $value): string
    {
        if ($value instanceof \UnitEnum) {
            return '\\' . $value::class . '::' . $value->name;
        }

        if ($value === null || is_object($value)) {
            // var_export() would render `NULL`, which is legal but jarring in
            // generated source next to hand-written PHP.
            return 'null';
        }

        $rendered = var_export($value, return: true);
        \assert($rendered !== '');

        return $rendered;
    }

    private static function describe(\ReflectionMethod $method): string
    {
        return '`' . $method->getDeclaringClass()->getName() . '::' . $method->getName() . '()`';
    }
}
