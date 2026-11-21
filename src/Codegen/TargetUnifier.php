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
        /** @var array<non-empty-string, \ReflectionMethod> $classStatics */
        $classStatics = [];

        foreach ($targets as $target) {
            $filter = \ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_PROTECTED;

            foreach ($target->getMethods($filter) as $method) {
                if (self::isOverridable($method, $target)) {
                    $byName[$method->getName()][] = $method;
                } elseif ($method->isStatic()) {
                    // What is left here is an implemented static on a class
                    // target: the generated subclass inherits it. Keep it only
                    // for compatibility checks against same-named interface
                    // declarations; it must not be rendered as a dispatcher of
                    // its own.
                    $classStatics[$method->getName()] = $method;
                }
            }
        }

        $signatures = [];

        foreach ($classStatics as $name => $classStatic) {
            $interfaceDeclarations = $byName[$name] ?? [];

            foreach ($interfaceDeclarations as $interfaceDeclaration) {
                if (!$interfaceDeclaration->isStatic()) {
                    throw UnsupportedTarget::signatureConflict(
                        $name,
                        self::describe($classStatic) . ' is static',
                        self::describe($interfaceDeclaration) . ' is an instance method',
                    );
                }

                if (!ReturnTypeUnifier::satisfies($classStatic, $interfaceDeclaration)) {
                    throw UnsupportedTarget::signatureConflict(
                        $name,
                        self::describe($classStatic) . ' declares `: '
                        . TypeRenderer::returnType(
                            ReturnTypeUnifier::declaredType($classStatic),
                            $classStatic->getDeclaringClass(),
                        ) . '`',
                        self::describe($interfaceDeclaration) . ' declares `: '
                        . TypeRenderer::returnType(
                            ReturnTypeUnifier::declaredType($interfaceDeclaration),
                            $interfaceDeclaration->getDeclaringClass(),
                        ) . '`',
                    );
                }

                if (!self::staticContractSatisfies($classStatic, $interfaceDeclaration)) {
                    throw UnsupportedTarget::signatureConflict(
                        $name,
                        self::describe($classStatic) . ' has an incompatible static signature',
                        self::describe($interfaceDeclaration) . ' requires a compatible static signature',
                    );
                }
            }

            // The inherited implementation is the correct behavior for a
            // class static method. There is no instance state to dispatch.
            unset($byName[$name]);
        }

        foreach ($byName as $name => $declarations) {
            \assert($declarations !== []);

            $signatures[$name] = self::unifyMethod($name, $declarations);
        }

        return $signatures;
    }

    /**
     * Which of a target's methods the generated class redeclares.
     *
     * The constructor is never one of them: a double is built with
     * `newInstanceWithoutConstructor()`, so redeclaring it would only invite
     * somebody to call it. `__destruct` and `__clone` are generated separately
     * — a destructor cannot carry a return type at all, and a clone has to
     * register the copy as a double of its own rather than dispatch.
     *
     * A static method declared by a *class* keeps the parent's implementation:
     * there is no instance state to intercept and silently replacing it would
     * change what the target does. An interface leaves no implementation to
     * keep, so its static declarations still get the override that rejects the
     * call and points at dependency injection.
     *
     * @param \ReflectionClass<object> $target
     */
    private static function isOverridable(\ReflectionMethod $method, \ReflectionClass $target): bool
    {
        if (\in_array($method->getName(), ['__construct', '__destruct', '__clone'], strict: true)) {
            return false;
        }

        // An abstract static has nothing to inherit — a class target can carry
        // one either by declaring it itself or by leaving an interface's
        // unimplemented — so the generated class has to declare it. PHP
        // refuses a concrete subclass that does not, and refuses it with a
        // fatal error rather than an exception a test could catch.
        return !$method->isStatic() || $target->isInterface() || $method->isAbstract();
    }

    private static function staticContractSatisfies(
        \ReflectionMethod $implementation,
        \ReflectionMethod $required,
    ): bool {
        if (!$implementation->isPublic()
            || $implementation->returnsReference() !== $required->returnsReference()
        ) {
            return false;
        }

        $implementationParameters = $implementation->getParameters();
        $requiredParameters = $required->getParameters();
        $requiredCount = count($requiredParameters);

        if ($implementation->getNumberOfRequiredParameters() > $requiredCount) {
            return false;
        }

        // Past its fixed parameters an implementation has only a variadic tail
        // to align the contract's remaining ones against. Without one it is
        // simply too short, which is what the `null` below stands for.
        $tail = $implementation->isVariadic()
            ? array_slice($implementationParameters, -1)[0] ?? null
            : null;

        foreach ($requiredParameters as $position => $requiredParameter) {
            $implementationParameter = $implementationParameters[$position] ?? $tail;

            if ($implementationParameter === null) {
                return false;
            }

            if ($requiredParameter->isVariadic() && !$implementationParameter->isVariadic()) {
                return false;
            }

            if ($requiredParameter->isPassedByReference() !== $implementationParameter->isPassedByReference()
                || !self::parameterTypeSatisfies(
                    $implementationParameter->getType(),
                    $requiredParameter->getType(),
                    $implementation->getDeclaringClass(),
                    $required->getDeclaringClass(),
                )
            ) {
                return false;
            }

            if ($requiredParameter->isOptional() && !$implementationParameter->isOptional()) {
                return false;
            }
        }

        return true;
    }

    private static function parameterTypeSatisfies(
        ?\ReflectionType $implementation,
        ?\ReflectionType $required,
        \ReflectionClass $implementationClass,
        \ReflectionClass $requiredClass,
    ): bool {
        $implementationBranches = DnfType::fromReflection($implementation, $implementationClass)->branches;
        $requiredBranches = DnfType::fromReflection($required, $requiredClass)->branches;

        foreach ($requiredBranches as $requiredBranch) {
            $accepted = false;

            foreach ($implementationBranches as $implementationBranch) {
                if (self::typeBranchSubsetOf($requiredBranch, $implementationBranch)) {
                    $accepted = true;

                    break;
                }
            }

            if (!$accepted) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param non-empty-list<non-empty-string> $required
     * @param non-empty-list<non-empty-string> $implementation
     */
    private static function typeBranchSubsetOf(array $required, array $implementation): bool
    {
        foreach ($implementation as $implementationAtom) {
            $accepted = false;

            foreach ($required as $requiredAtom) {
                if (DnfType::atomSatisfies($requiredAtom, $implementationAtom)) {
                    $accepted = true;

                    break;
                }
            }

            if (!$accepted) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param non-empty-string              $name
     * @param non-empty-list<\ReflectionMethod> $declarations
     */
    private static function unifyMethod(string $name, array $declarations): MethodSignature
    {
        $returnType = ReturnTypeUnifier::unify($name, $declarations);
        $static = $declarations[0]->isStatic();

        foreach ($declarations as $declaration) {
            if ($declaration->isStatic() !== $static) {
                throw UnsupportedTarget::signatureConflict(
                    $name,
                    self::describe($declarations[0]) . ' is ' . ($static ? 'static' : 'an instance method'),
                    self::describe($declaration) . ' is ' . ($declaration->isStatic() ? 'static' : 'an instance method'),
                );
            }
        }
        $arity = max(array_map(
            static fn(\ReflectionMethod $method): int => $method->getNumberOfParameters(),
            $declarations,
        ));

        // A variadic absorbs every parameter after it, in the contract and in
        // the override alike. Rendering the later fixed parameters of another
        // target would put them after `...$rest` — which is a parse error, not
        // a wider signature.
        $variadicAt = ParameterUnifier::firstVariadicPosition($declarations);
        $arity = $variadicAt ?? $arity;

        $parameters = [];
        $arguments = [];
        $byReferenceParameters = false;
        $parameterNames = [];
        $optionalParameters = [];

        for ($position = 0; $position < $arity; $position++) {
            $parameter = ParameterUnifier::unify($name, $declarations, $position);
            $parameters[] = $parameter['rendered'];
            $parameterNames[$position] = $parameter['name'];

            // Only the optional positions are listed, and each one carries the
            // reflection of the parameter that declared the default rather than
            // the value: `new Foo()` as a default builds one instance per call
            // in PHP, and evaluating it once at generation time would hand
            // every caller the same object.
            if ($parameter['optional']) {
                $optionalParameters[$position] = $parameter['default'];
            }

            // Collected by name rather than with func_get_args(), which omits
            // parameters the caller left at their default: the call log must
            // show the arguments the method actually received, so that
            // `tag('alpha')` and `tag('alpha', 1)` verify as the same call.
            //
            // A by-reference parameter is collected as a reference. `[$slot]`
            // would copy it, and a forwarded method could never write back to
            // the caller's variable — the one thing declaring `&` promises.
            $byReferenceParameters = $byReferenceParameters || $parameter['byReference'];
            $arguments[] = ($parameter['byReference'] ? '&$' : '$') . $parameter['name'];
        }

        if ($variadicAt !== null) {
            $tail = ParameterUnifier::variadicTail($name, $declarations, $variadicAt);
            $parameters[] = $tail['rendered'];
            $arguments[] = '...$' . $tail['name'];
        }

        $reference = $declarations[0]->returnsReference();

        foreach ($declarations as $declaration) {
            if ($declaration->returnsReference() !== $reference) {
                throw UnsupportedTarget::signatureConflict(
                    $name,
                    self::describe($declarations[0]) . ' returns by ' . ($reference ? 'reference' : 'value'),
                    self::describe($declaration) . ' returns by ' . ($reference ? 'value' : 'reference'),
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
            hasReferenceParameters: $byReferenceParameters,
            static: $static,
            // An override may widen visibility but never narrow it, so one
            // public declaration makes the whole override public.
            visibility: self::visibilityOf($declarations),
            sensitiveParameters: self::sensitiveParameters($declarations),
            parameterNames: $parameterNames,
            optionalParameters: $optionalParameters,
        );
    }

    /**
     * Positions any declaration marks `#[\SensitiveParameter]`.
     *
     * Any, not all: the attribute is a statement that the value must not be
     * printed, and one contract asking for that is enough. PHP redacts such
     * parameters in its own stack traces; understudy formats arguments itself,
     * so without this the value went verbatim into the failure message and the
     * transcript — which is to say into a CI log.
     *
     * @param non-empty-list<\ReflectionMethod> $declarations
     *
     * @return list<int>
     */
    private static function sensitiveParameters(array $declarations): array
    {
        $positions = [];

        foreach ($declarations as $declaration) {
            foreach ($declaration->getParameters() as $position => $parameter) {
                if ($parameter->getAttributes(\SensitiveParameter::class) !== []) {
                    $positions[$position] = true;
                }
            }
        }

        $sorted = array_keys($positions);
        sort($sorted);

        return $sorted;
    }

    /**
     * @param non-empty-list<\ReflectionMethod> $declarations
     *
     * @return 'public'|'protected'
     */
    private static function visibilityOf(array $declarations): string
    {
        foreach ($declarations as $declaration) {
            if ($declaration->isPublic()) {
                return 'public';
            }
        }

        return 'protected';
    }

    /**
     * @return non-empty-string
     */
    public static function describe(\ReflectionMethod $method): string
    {
        return '`' . $method->getDeclaringClass()->getName() . '::' . $method->getName() . '()`';
    }
}
