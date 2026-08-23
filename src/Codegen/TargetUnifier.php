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
    private const string TARGET_SELF_RETURN_PREFIX = '@target-self:';
    private const string GENERATED_STATIC_RETURN = '@generated-static';

    /** Used when a target declares a variadic without a usable name. */
    private const string DEFAULT_TAIL_NAME = 'rest';

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
                } elseif (!$target->isInterface()
                    && $method->isStatic()
                    && !$method->getDeclaringClass()->isInterface()
                ) {
                    // A class static method is inherited by the generated
                    // subclass. Keep it only for compatibility checks against
                    // same-named interface declarations; it must not be
                    // rendered as a dispatcher of its own.
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

                if (!self::returnTypeSatisfies($classStatic, $interfaceDeclaration)) {
                    throw UnsupportedTarget::signatureConflict(
                        $name,
                        self::describe($classStatic) . ' declares `: '
                        . TypeRenderer::returnType($classStatic->getReturnType()) . '`',
                        self::describe($interfaceDeclaration) . ' declares `: '
                        . TypeRenderer::returnType($interfaceDeclaration->getReturnType()) . '`',
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

        return !$method->isStatic() || $target->isInterface();
    }

    /**
     * @param non-empty-string              $name
     * @param non-empty-list<\ReflectionMethod> $declarations
     */
    private static function unifyMethod(string $name, array $declarations): MethodSignature
    {
        $returnType = self::unifyReturnType($name, $declarations);
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
        $variadicAt = self::firstVariadicPosition($declarations);
        $arity = $variadicAt ?? $arity;

        $parameters = [];
        $arguments = [];
        $byReferenceParameters = false;

        for ($position = 0; $position < $arity; $position++) {
            $parameter = self::unifyParameter($name, $declarations, $position);
            $parameters[] = $parameter['rendered'];

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
            $tail = self::unifyVariadicTail($name, $declarations, $variadicAt);
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
        );
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
        foreach ($declarations as $candidate) {
            $satisfiesAll = true;

            foreach ($declarations as $required) {
                if (!self::returnTypeSatisfies($candidate, $required)) {
                    $satisfiesAll = false;

                    break;
                }
            }

            if ($satisfiesAll) {
                return TypeRenderer::returnType($candidate->getReturnType());
            }
        }

        $intersection = self::intersectReturnTypes($declarations);

        if ($intersection !== null) {
            return $intersection;
        }

        [$left, $right] = self::conflictingReturnDeclarations($declarations);

        throw UnsupportedTarget::signatureConflict(
            $name,
            self::describe($left) . ' declares `: ' . TypeRenderer::returnType($left->getReturnType()) . '`',
            self::describe($right) . ' declares `: ' . TypeRenderer::returnType($right->getReturnType()) . '`',
        );
    }

    /**
     * Finds the common part of return-type unions. Existing compatible
     * branches keep their narrower type; otherwise interface-only branches
     * are combined into an intersection such as `Alpha&Beta`.
     *
     * @param non-empty-list<\ReflectionMethod> $declarations
     *
     * @return non-empty-string|null
     */
    private static function intersectReturnTypes(array $declarations): ?string
    {
        $first = $declarations[0];
        $branches = self::returnTypeBranches($first->getReturnType(), $first->getDeclaringClass());

        foreach (array_slice($declarations, 1) as $declaration) {
            $requiredBranches = self::returnTypeBranches(
                $declaration->getReturnType(),
                $declaration->getDeclaringClass(),
            );
            $intersections = [];

            foreach ($branches as $candidateBranch) {
                foreach ($requiredBranches as $requiredBranch) {
                    $intersection = self::intersectReturnBranches($candidateBranch, $requiredBranch);

                    if ($intersection !== null) {
                        self::addReturnBranch($intersections, $intersection);
                    }
                }
            }

            if ($intersections === []) {
                return null;
            }

            $branches = $intersections;
        }

        return self::renderReturnBranches($branches);
    }

    /**
     * @param non-empty-list<non-empty-string> $left
     * @param non-empty-list<non-empty-string> $right
     *
     * @return non-empty-list<non-empty-string>|null
     */
    private static function intersectReturnBranches(array $left, array $right): ?array
    {
        if (self::returnBranchSatisfies($left, $right)) {
            return $left;
        }

        if (self::returnBranchSatisfies($right, $left)) {
            return $right;
        }

        $atoms = [...$left, ...$right];

        foreach ($atoms as $atom) {
            if (!self::returnAtomIsInterface($atom)) {
                return null;
            }
        }

        $intersection = [];

        foreach ($atoms as $atom) {
            $redundant = false;

            foreach ($intersection as $position => $existing) {
                if (self::returnAtomSatisfies($atom, $existing)) {
                    unset($intersection[$position]);
                    continue;
                }

                if (self::returnAtomSatisfies($existing, $atom)) {
                    $redundant = true;
                    break;
                }
            }

            if (!$redundant) {
                $intersection[] = $atom;
            }
        }

        /** @var non-empty-list<non-empty-string> */
        return array_values($intersection);
    }

    /**
     * @param list<non-empty-list<non-empty-string>> $branches
     * @param non-empty-list<non-empty-string>       $candidate
     */
    private static function addReturnBranch(array &$branches, array $candidate): void
    {
        foreach ($branches as $position => $existing) {
            if (self::returnBranchSatisfies($candidate, $existing)) {
                return;
            }

            if (self::returnBranchSatisfies($existing, $candidate)) {
                unset($branches[$position]);
            }
        }

        $branches[] = $candidate;
        $branches = array_values($branches);
    }

    /**
     * @param non-empty-list<non-empty-list<non-empty-string>> $branches
     *
     * @return non-empty-string
     */
    private static function renderReturnBranches(array $branches): string
    {
        $union = [];
        $dnf = count($branches) > 1;

        foreach ($branches as $branch) {
            $intersection = implode('&', array_map(self::renderReturnAtom(...), $branch));
            $union[] = $dnf && count($branch) > 1 ? '(' . $intersection . ')' : $intersection;
        }

        return implode('|', $union);
    }

    /**
     * @param non-empty-string $atom
     *
     * @return non-empty-string
     */
    private static function renderReturnAtom(string $atom): string
    {
        if (str_starts_with($atom, self::TARGET_SELF_RETURN_PREFIX)) {
            return '\\' . substr($atom, strlen(self::TARGET_SELF_RETURN_PREFIX));
        }

        return $atom === self::GENERATED_STATIC_RETURN ? 'static' : $atom;
    }

    /**
     * Reports an actually incompatible pair instead of always blaming the
     * first two declarations when a later target introduced the conflict.
     *
     * @param non-empty-list<\ReflectionMethod> $declarations
     *
     * @return array{\ReflectionMethod, \ReflectionMethod}
     */
    private static function conflictingReturnDeclarations(array $declarations): array
    {
        foreach ($declarations as $leftPosition => $left) {
            foreach (array_slice($declarations, $leftPosition + 1) as $right) {
                if (self::returnTypeSatisfies($left, $right) || self::returnTypeSatisfies($right, $left)) {
                    continue;
                }

                if (self::intersectReturnTypes([$left, $right]) === null) {
                    return [$left, $right];
                }
            }
        }

        return [$declarations[0], $declarations[array_key_last($declarations)]];
    }

    private static function returnTypeSatisfies(\ReflectionMethod $candidate, \ReflectionMethod $required): bool
    {
        $candidateBranches = self::returnTypeBranches($candidate->getReturnType(), $candidate->getDeclaringClass());
        $requiredBranches = self::returnTypeBranches($required->getReturnType(), $required->getDeclaringClass());

        foreach ($candidateBranches as $candidateBranch) {
            $accepted = false;

            foreach ($requiredBranches as $requiredBranch) {
                if (self::returnBranchSatisfies($candidateBranch, $requiredBranch)) {
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
     * @return non-empty-list<non-empty-list<non-empty-string>>
     */
    private static function returnTypeBranches(
        ?\ReflectionType $type,
        \ReflectionClass $declaringClass,
    ): array {
        if ($type === null) {
            return [['mixed']];
        }

        if ($type instanceof \ReflectionUnionType) {
            $branches = [];

            foreach ($type->getTypes() as $part) {
                $branches = [...$branches, ...self::returnTypeBranches($part, $declaringClass)];
            }

            return $branches;
        }

        if ($type instanceof \ReflectionIntersectionType) {
            $branch = [];

            foreach ($type->getTypes() as $part) {
                $branch[] = self::returnTypeAtom($part, $declaringClass);
            }

            return [$branch];
        }

        \assert($type instanceof \ReflectionNamedType);
        $branches = [[self::returnTypeAtom($type, $declaringClass)]];

        if ($type->allowsNull() && !in_array($type->getName(), ['mixed', 'null'], strict: true)) {
            $branches[] = ['null'];
        }

        return $branches;
    }

    /**
     * @return non-empty-string
     */
    private static function returnTypeAtom(
        \ReflectionNamedType $type,
        \ReflectionClass $declaringClass,
    ): string {
        $name = $type->getName();

        // Reflection resolves `self` in an interface to that interface's FQCN.
        // Keep its origin so generated `static` can satisfy it without making
        // two different target interfaces look like the same return type.
        if ($name === 'self' || (!$type->isBuiltin() && $name === $declaringClass->getName())) {
            return self::TARGET_SELF_RETURN_PREFIX . $declaringClass->getName();
        }

        if ($name === 'static') {
            return self::GENERATED_STATIC_RETURN;
        }

        if ($name === 'parent') {
            $parent = $declaringClass->getParentClass();

            return $parent === false ? 'parent' : '\\' . $parent->getName();
        }

        return $type->isBuiltin() ? $name : '\\' . $name;
    }

    /**
     * @param non-empty-list<non-empty-string> $candidate
     * @param non-empty-list<non-empty-string> $required
     */
    private static function returnBranchSatisfies(array $candidate, array $required): bool
    {
        foreach ($required as $requiredAtom) {
            $satisfied = false;

            foreach ($candidate as $candidateAtom) {
                if (self::returnAtomSatisfies($candidateAtom, $requiredAtom)) {
                    $satisfied = true;

                    break;
                }
            }

            if (!$satisfied) {
                return false;
            }
        }

        return true;
    }

    private static function returnAtomSatisfies(string $candidate, string $required): bool
    {
        if ($candidate === 'never' || $required === 'mixed' || $candidate === $required) {
            return true;
        }

        // The emitted class implements every target interface. Its `static`
        // return is therefore covariant with each target's own interface type.
        if ($candidate === self::GENERATED_STATIC_RETURN
            && str_starts_with($required, self::TARGET_SELF_RETURN_PREFIX)
        ) {
            return true;
        }

        if ($required === 'object') {
            return $candidate === 'object'
                || str_starts_with($candidate, '\\')
                || str_starts_with($candidate, self::TARGET_SELF_RETURN_PREFIX)
                || $candidate === self::GENERATED_STATIC_RETURN;
        }

        if ($required === 'bool' && in_array($candidate, ['true', 'false'], strict: true)) {
            return true;
        }

        if ($required === 'iterable') {
            return in_array($candidate, ['array', 'iterable'], strict: true)
                || (str_starts_with($candidate, '\\') && is_a(ltrim($candidate, '\\'), \Traversable::class, allow_string: true));
        }

        if ($required === 'callable' && $candidate === '\\Closure') {
            return true;
        }

        $candidate = self::returnAtomClass($candidate);
        $required = self::returnAtomClass($required);

        if ($candidate === null || $required === null) {
            return false;
        }

        /** @var class-string $candidateClass */
        $candidateClass = $candidate;
        /** @var class-string $requiredClass */
        $requiredClass = $required;

        return is_a($candidateClass, $requiredClass, allow_string: true);
    }

    private static function returnAtomIsInterface(string $atom): bool
    {
        $class = self::returnAtomClass($atom);

        return $class !== null && interface_exists($class);
    }

    /**
     * @return class-string|null
     */
    private static function returnAtomClass(string $atom): ?string
    {
        if (str_starts_with($atom, self::TARGET_SELF_RETURN_PREFIX)) {
            /** @var class-string */
            return substr($atom, strlen(self::TARGET_SELF_RETURN_PREFIX));
        }

        if (!str_starts_with($atom, '\\')) {
            return null;
        }

        /** @var class-string */
        return ltrim($atom, '\\');
    }

    /**
     * The earliest position at which any target declares a variadic, or null
     * when none does.
     *
     * @param non-empty-list<\ReflectionMethod> $declarations
     *
     * @return int<0, max>|null
     */
    private static function firstVariadicPosition(array $declarations): ?int
    {
        $earliest = null;

        foreach ($declarations as $declaration) {
            foreach ($declaration->getParameters() as $position => $parameter) {
                if ($parameter->isVariadic()) {
                    $earliest = $earliest === null ? $position : min($earliest, $position);

                    break;
                }
            }
        }

        return $earliest;
    }

    /**
     * Everything from the first variadic position onwards collapses into one
     * variadic tail whose type is the union of every parameter any target
     * declares there or later.
     *
     * @param non-empty-string                  $name
     * @param non-empty-list<\ReflectionMethod> $declarations
     *
     * @return array{rendered: non-empty-string, name: non-empty-string}
     */
    private static function unifyVariadicTail(string $name, array $declarations, int $from): array
    {
        /** @var array<string, true> $types */
        $types = [];
        $byReference = null;
        $byReferenceSource = null;
        $tailName = self::DEFAULT_TAIL_NAME;
        $untyped = false;
        $acceptsMatcher = false;

        foreach ($declarations as $declaration) {
            foreach (array_slice($declaration->getParameters(), $from) as $parameter) {
                $rendered = TypeRenderer::parameterType($parameter->getType());

                if ($rendered === '') {
                    $untyped = true;
                } else {
                    foreach (self::splitUnion($rendered) as $part) {
                        $types[$part] = true;
                    }
                }

                $acceptsMatcher = $acceptsMatcher || TypeRenderer::acceptsMatcher($parameter->getType());

                if ($byReference !== null && $byReference !== $parameter->isPassedByReference()) {
                    // Naming `$declaration` on both lines would report the same
                    // target twice; the other side is whoever set the mode.
                    \assert($byReferenceSource !== null);

                    throw UnsupportedTarget::signatureConflict(
                        $name,
                        self::describe($byReferenceSource) . ' takes its variadic tail by ' . ($byReference ? 'reference' : 'value'),
                        self::describe($declaration) . ' takes it by ' . ($parameter->isPassedByReference() ? 'reference' : 'value'),
                    );
                }

                $byReference = $parameter->isPassedByReference();
                $byReferenceSource = $declaration;

                if ($parameter->isVariadic() && $tailName === self::DEFAULT_TAIL_NAME) {
                    // First target wins: `for($primary, ...$interfaces)` treats
                    // the first contract as the primary one, and the name is
                    // what a named argument would have to use.
                    $tailName = $parameter->getName();
                }
            }
        }

        if (!$untyped && !isset($types['mixed']) && !$acceptsMatcher && $types !== []) {
            $types[TypeRenderer::MATCHER] = true;
        }

        $type = match (true) {
            $untyped => '',
            isset($types['mixed']) => 'mixed',
            default => implode('|', array_keys($types)),
        };
        $rendered = trim($type . ' ' . ($byReference === true ? '&' : '') . '...$' . $tailName);
        \assert($rendered !== '');

        return ['rendered' => $rendered, 'name' => $tailName];
    }

    /**
     * @param non-empty-string              $name
     * @param non-empty-list<\ReflectionMethod> $declarations
     *
     * @return array{rendered: non-empty-string, name: non-empty-string, byReference: bool}
     */
    private static function unifyParameter(string $name, array $declarations, int $position): array
    {
        /** @var array<string, true> $types */
        $types = [];
        $byReference = null;
        $firstToDeclare = null;
        $declaredEverywhere = true;
        $requiredEverywhere = true;
        $parameterName = 'p' . $position;
        $untyped = false;
        $acceptsMatcher = false;
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

            $acceptsMatcher = $acceptsMatcher || TypeRenderer::acceptsMatcher($parameter->getType());

            if ($firstToDeclare === null) {
                $firstToDeclare = $declaration;
                $parameterName = $parameter->getName();
            }

            if ($byReference !== null && $byReference !== $parameter->isPassedByReference()) {
                // `$declarations[0]` may not declare this position at all;
                // name the target that actually did.
                throw UnsupportedTarget::signatureConflict(
                    $name,
                    self::describe($firstToDeclare) . ' takes parameter #' . ($position + 1) . ' by ' . ($byReference ? 'reference' : 'value'),
                    self::describe($declaration) . ' takes it by ' . ($parameter->isPassedByReference() ? 'reference' : 'value'),
                );
            }

            $byReference = $parameter->isPassedByReference();
            $requiredEverywhere = $requiredEverywhere && !$parameter->isOptional();

            if ($parameter->isDefaultValueAvailable()) {
                $declaredDefaults[self::renderDefault($parameter, $name)] = true;
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
        } elseif (isset($types['mixed'])) {
            $type = 'mixed';
        } else {
            if (!$acceptsMatcher) {
                $types[TypeRenderer::MATCHER] = true;
            }

            $type = implode('|', array_keys($types));
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

        return ['rendered' => $rendered, 'name' => $parameterName, 'byReference' => $byReference === true];
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
     * Renders a parameter's default as source the generated class can carry.
     *
     * Faithfulness is the point: an omitted argument has to be logged as the
     * value the target would have received, or `tag('a')` and `tag('a', 1)`
     * stop verifying as the same call. Anything that cannot be reproduced
     * exactly rejects the target instead of quietly becoming `null` — a double
     * that answers with a different default than the real thing is a test that
     * passes for the wrong reason.
     *
     * The source expression comes from `ReflectionParameter::__toString()`,
     * which renders it fully qualified and without reading the declaring file,
     * so it works for `eval`'d and internal declarations too. It is also the
     * only way to *see* an object default without evaluating it:
     * `getDefaultValue()` on `= new Foo()` runs the constructor.
     *
     * @param non-empty-string $method
     *
     * @return non-empty-string
     */
    private static function renderDefault(\ReflectionParameter $parameter, string $method): string
    {
        $source = self::defaultSource($parameter);

        if ($source !== null && self::buildsAnObject($source)) {
            return self::renderSourceDefault($parameter, $method, $source);
        }

        if ($parameter->isDefaultValueConstant()) {
            $rendered = self::renderConstantDefault($parameter);

            if ($rendered !== null) {
                return $rendered;
            }
        }

        return self::renderValueDefault($parameter, $method);
    }

    /**
     * The default expression as PHP would print it, or null when Reflection
     * reports none.
     */
    private static function defaultSource(\ReflectionParameter $parameter): ?string
    {
        $rendered = (string) $parameter;
        $position = strpos($rendered, ' = ');

        if ($position === false) {
            return null;
        }

        if (!str_ends_with($rendered, ' ]')) {
            return null;
        }

        return substr($rendered, $position + \strlen(' = '), -\strlen(' ]'));
    }

    /**
     * True when the default builds an object anywhere inside it — `new Foo()`,
     * but also `[new Foo()]` or `['k' => new Foo()]`, which are just as legal in
     * an initializer and just as destructive to evaluate: `getDefaultValue()`
     * runs every constructor in there.
     *
     * Quoted text is blanked out first, so a string default that merely contains
     * the word is not mistaken for an expression.
     */
    private static function buildsAnObject(string $source): bool
    {
        return preg_match('/\bnew\s+[\\\\A-Za-z_]/', self::withoutStringLiterals($source)) === 1;
    }

    /**
     * Blanks out single- and double-quoted runs, keeping the length so nothing
     * else shifts. An escaped quote stays inside its run.
     */
    private static function withoutStringLiterals(string $source): string
    {
        return (string) preg_replace_callback(
            '/\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*"/s',
            static fn(array $match): string => str_repeat(' ', \strlen($match[0])),
            $source,
        );
    }

    /**
     * Renders the default from its own source, which Reflection has already
     * qualified — and which is the only form reproducible without evaluating it.
     *
     * What Reflection does not qualify is `self`, `static` and `parent`: they
     * would resolve against the generated class, a different class with
     * different constants, so a target using them is rejected by name.
     *
     * @param non-empty-string $method
     *
     * @return non-empty-string
     */
    private static function renderSourceDefault(\ReflectionParameter $parameter, string $method, string $source): string
    {
        if (preg_match('/\b(self|static|parent)\s*::/i', self::withoutStringLiterals($source)) === 1) {
            throw UnsupportedTarget::notDoublable(
                $parameter->getDeclaringClass()?->getName() ?? $method,
                sprintf(
                    'the default of `$%s` in `%s()` is `%s`, which resolves `self`, `static` or `parent` '
                    . 'against the declaring class. The generated double is a different class, so the value '
                    . 'cannot be reproduced. Give the parameter a default the double can build, or double '
                    . 'an interface.',
                    $parameter->getName(),
                    $method,
                    $source,
                ),
            );
        }

        \assert($source !== '');

        return $source;
    }

    /**
     * A class constant or enum case, rendered through its declaring class so
     * that `self::` does not follow the double into a class that never had the
     * constant. Returns null when the constant cannot be named from outside —
     * a private or protected one, or a global constant, which Reflection
     * reports prefixed with the declaring namespace and therefore under a name
     * that does not exist.
     *
     * @return non-empty-string|null
     */
    private static function renderConstantDefault(\ReflectionParameter $parameter): ?string
    {
        $name = $parameter->getDefaultValueConstantName();

        if ($name === null) {
            return null;
        }

        if (!str_contains($name, '::')) {
            return null;
        }

        $parts = explode('::', $name, 2);
        $class = $parts[0];
        $constant = $parts[1] ?? '';
        $declaring = $parameter->getDeclaringClass();

        if (\in_array(strtolower($class), ['self', 'static', 'parent'], strict: true)) {
            if ($declaring === null) {
                return null;
            }

            if (strtolower($class) === 'parent') {
                $parent = $declaring->getParentClass();

                if ($parent === false) {
                    return null;
                }

                $class = $parent->getName();
            } else {
                $class = $declaring->getName();
            }
        }

        if (!class_exists($class) && !interface_exists($class) && !enum_exists($class)) {
            return null;
        }

        $reflection = new \ReflectionClass($class);

        if (!$reflection->hasConstant($constant)) {
            return null;
        }

        $declared = $reflection->getReflectionConstant($constant);

        if ($declared === false || !$declared->isPublic()) {
            // Reachable only from inside the declaring hierarchy, which the
            // generated class is not part of; fall back to the value.
            return null;
        }

        return '\\' . $class . '::' . $constant;
    }

    /**
     * The evaluated value, for everything that survives `var_export()`: scalars,
     * null, arrays of them, and enum cases. An object anywhere inside would be
     * rendered as a `::__set_state()` call the double cannot honour, so the
     * target is rejected rather than given a default that differs from the
     * contract's.
     *
     * @param non-empty-string $method
     *
     * @return non-empty-string
     */
    private static function renderValueDefault(\ReflectionParameter $parameter, string $method): string
    {
        /** @var mixed $value */
        $value = $parameter->getDefaultValue();

        if (self::holdsPlainObject($value)) {
            throw UnsupportedTarget::notDoublable(
                $parameter->getDeclaringClass()?->getName() ?? $method,
                sprintf(
                    'the default of `$%s` in `%s()` holds an object that cannot be written back as source. '
                    . 'Give the parameter a scalar, array or enum default, or double an interface.',
                    $parameter->getName(),
                    $method,
                ),
            );
        }

        // `var_export()` renders null as `NULL`, which is legal but jarring in
        // generated source next to hand-written PHP.
        $rendered = $value === null ? 'null' : var_export($value, return: true);
        \assert($rendered !== '');

        return $rendered;
    }

    /**
     * True when the value is, or contains, an object that is not an enum case.
     * Enum cases round-trip through `var_export()`; nothing else does.
     */
    private static function holdsPlainObject(mixed $value): bool
    {
        if ($value instanceof \UnitEnum) {
            return false;
        }

        if (\is_object($value)) {
            return true;
        }

        if (!\is_array($value)) {
            return false;
        }

        /** @var mixed $item */
        foreach ($value as $item) {
            if (self::holdsPlainObject($item)) {
                return true;
            }
        }

        return false;
    }

    private static function describe(\ReflectionMethod $method): string
    {
        return '`' . $method->getDeclaringClass()->getName() . '::' . $method->getName() . '()`';
    }
}
