<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Codegen;

/**
 * A declared type in disjunctive normal form: a union of branches, each an
 * intersection of atoms — `(A&B)|null` is `[['\A', '\B'], ['null']]`.
 *
 * The one reading of a type the engine shares. Types used to live as strings
 * and were split on a depth-aware `|` in three places, with a separate
 * "value is in the type" table in each of the return contract, the wiring and
 * the unifier. A string is rendered from this only where code is generated.
 *
 * Atoms are builtin names (`int`, `null`, `mixed`), absolute class names
 * (`\App\Book`), or — only when read from Reflection — two markers the
 * unifier needs: the target's own `self` and the generated class's `static`.
 *
 * @internal
 */
final readonly class DnfType
{
    /** A target's `self`, kept apart from a plain class name of the same target. */
    public const string TARGET_SELF = '@target-self:';

    /** The generated class's `static`, which satisfies every target's `self`. */
    public const string GENERATED_STATIC = '@generated-static';

    /**
     * @param non-empty-list<non-empty-list<non-empty-string>> $branches
     */
    private function __construct(
        public array $branches,
    ) {}

    /**
     * Reads a rendered type: `?T`, a union, an intersection, a DNF union with
     * parenthesised intersections. Parsed once per distinct string — the
     * dispatcher asks for the same few return types on every unmatched call.
     */
    public static function parse(string $rendered): self
    {
        /** @var array<string, self> $parsed */
        static $parsed = [];

        return $parsed[$rendered] ??= new self(self::branchesOf($rendered));
    }

    /**
     * @param non-empty-list<non-empty-list<non-empty-string>> $branches
     */
    public static function of(array $branches): self
    {
        return new self($branches);
    }

    /**
     * Reads a declared type from Reflection. An absent type is `mixed`.
     */
    public static function fromReflection(?\ReflectionType $type, ?\ReflectionClass $declaringClass = null): self
    {
        return new self(self::reflectedBranches($type, $declaringClass));
    }

    /**
     * Whether every value of this type is a value of the required one —
     * covariance, as PHP checks a return type against its parent.
     */
    public function satisfies(self $required): bool
    {
        foreach ($this->branches as $branch) {
            $accepted = false;

            foreach ($required->branches as $requiredBranch) {
                if (self::branchSatisfies($branch, $requiredBranch)) {
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
     * Whether a value can be answered where this type is declared, by the
     * rules of a generated method — which is not under `strict_types`: a
     * scalar type takes any scalar and `string` takes a `Stringable`. A class
     * name nobody can load is accepted, so a wrong refusal is impossible.
     *
     * @param non-empty-string $selfContract what `self` and `static` resolve to
     */
    public function accepts(mixed $value, string $selfContract): bool
    {
        foreach ($this->branches as $branch) {
            if (self::branchAccepts($branch, static fn(string $atom): bool => self::atomAcceptsCoercively($atom, $value, $selfContract))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a value is of this type exactly, as `strict_types` would see
     * it — with the one widening PHP keeps there, an `int` for a `float`.
     */
    public function acceptsStrictly(mixed $value): bool
    {
        foreach ($this->branches as $branch) {
            if (self::branchAccepts($branch, static fn(string $atom): bool => self::atomAcceptsStrictly($atom, $value))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The type as PHP source; DNF intersections parenthesised only inside a
     * union, the markers resolved to what the generated class declares.
     *
     * @return non-empty-string
     */
    public function render(): string
    {
        $union = count($this->branches) > 1;

        return implode('|', array_map(
            static fn(array $branch): string => self::renderBranch($branch, $union),
            $this->branches,
        ));
    }

    /**
     * Each branch on its own, as a union member: `(A&B)` and `null` for
     * `(A&B)|null`.
     *
     * @return non-empty-list<non-empty-string>
     */
    public function renderedBranches(): array
    {
        return array_map(
            static fn(array $branch): string => self::renderBranch($branch, union: true),
            $this->branches,
        );
    }

    /**
     * @param non-empty-list<non-empty-string> $candidate
     * @param non-empty-list<non-empty-string> $required
     */
    public static function branchSatisfies(array $candidate, array $required): bool
    {
        foreach ($required as $requiredAtom) {
            $satisfied = false;

            foreach ($candidate as $candidateAtom) {
                if (self::atomSatisfies($candidateAtom, $requiredAtom)) {
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

    public static function atomSatisfies(string $candidate, string $required): bool
    {
        if ($candidate === 'never' || $required === 'mixed' || $candidate === $required) {
            return true;
        }

        // The emitted class implements every target interface. Its `static`
        // return is therefore covariant with each target's own interface type.
        if ($candidate === self::GENERATED_STATIC
            && str_starts_with($required, self::TARGET_SELF)
        ) {
            return true;
        }

        if ($required === 'object') {
            return $candidate === 'object'
                || str_starts_with($candidate, '\\')
                || str_starts_with($candidate, self::TARGET_SELF)
                || $candidate === self::GENERATED_STATIC;
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

        $candidate = self::atomClass($candidate);
        $required = self::atomClass($required);

        if ($candidate === null || $required === null) {
            return false;
        }

        return is_a($candidate, $required, allow_string: true);
    }

    public static function atomIsInterface(string $atom): bool
    {
        $class = self::atomClass($atom);

        return $class !== null && interface_exists($class);
    }

    /**
     * @return class-string|null
     */
    public static function atomClass(string $atom): ?string
    {
        if (str_starts_with($atom, self::TARGET_SELF)) {
            /** @var class-string */
            return substr($atom, strlen(self::TARGET_SELF));
        }

        if (!str_starts_with($atom, '\\')) {
            return null;
        }

        /** @var class-string */
        return ltrim($atom, '\\');
    }

    /**
     * @param non-empty-list<non-empty-string> $branch
     *
     * @return non-empty-string
     */
    private static function renderBranch(array $branch, bool $union): string
    {
        $intersection = implode('&', array_map(self::renderAtom(...), $branch));

        return $union && count($branch) > 1 ? '(' . $intersection . ')' : $intersection;
    }

    /**
     * @param non-empty-string $atom
     *
     * @return non-empty-string
     */
    private static function renderAtom(string $atom): string
    {
        if (str_starts_with($atom, self::TARGET_SELF)) {
            return '\\' . substr($atom, strlen(self::TARGET_SELF));
        }

        return $atom === self::GENERATED_STATIC ? 'static' : $atom;
    }

    /**
     * @param non-empty-list<non-empty-string> $branch
     * @param \Closure(non-empty-string): bool $atomAccepts
     */
    private static function branchAccepts(array $branch, \Closure $atomAccepts): bool
    {
        foreach ($branch as $atom) {
            if (!$atomAccepts($atom)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param non-empty-string $selfContract
     */
    private static function atomAcceptsCoercively(string $atom, mixed $value, string $selfContract): bool
    {
        // A class name arrives absolute (`\App\Book`), which `class_exists()`
        // and `instanceof` both read as written.
        return match ($atom) {
            'mixed' => true,
            // `returns(null)` on a void method is the idiom for "answer
            // nothing"; a value is refused before this table is consulted.
            'null', 'void' => $value === null,
            'never' => false,
            'int', 'float', 'bool', 'true', 'false' => is_scalar($value),
            'string' => is_scalar($value) || $value instanceof \Stringable,
            'array' => is_array($value),
            'iterable' => is_iterable($value),
            'callable' => is_callable($value),
            'object' => is_object($value),
            'self', 'static', self::GENERATED_STATIC => $value instanceof $selfContract,
            default => self::acceptsUnknownOrInstance($atom, $value),
        };
    }

    private static function acceptsUnknownOrInstance(string $atom, mixed $value): bool
    {
        $class = self::atomClass($atom) ?? $atom;

        return !class_exists($class) && !interface_exists($class) || $value instanceof $class;
    }

    private static function atomAcceptsStrictly(string $atom, mixed $value): bool
    {
        return match ($atom) {
            'mixed' => true,
            'null' => $value === null,
            'bool' => is_bool($value),
            'true' => $value === true,
            'false' => $value === false,
            'int' => is_int($value),
            'float' => is_float($value) || is_int($value),
            'string' => is_string($value),
            'array' => is_array($value),
            'object' => is_object($value),
            'callable' => is_callable($value),
            'iterable' => is_iterable($value),
            default => ($class = self::atomClass($atom)) !== null && $value instanceof $class,
        };
    }

    /**
     * @return non-empty-list<non-empty-list<non-empty-string>>
     */
    private static function reflectedBranches(?\ReflectionType $type, ?\ReflectionClass $declaringClass): array
    {
        if (!$type instanceof \ReflectionType) {
            return [['mixed']];
        }

        if ($type instanceof \ReflectionUnionType) {
            $branches = [];

            foreach ($type->getTypes() as $part) {
                $branches = [...$branches, ...self::reflectedBranches($part, $declaringClass)];
            }

            /** @var non-empty-list<non-empty-list<non-empty-string>> */
            return $branches;
        }

        if ($type instanceof \ReflectionIntersectionType) {
            $branch = [];

            foreach ($type->getTypes() as $part) {
                $branch[] = self::reflectedAtom($part, $declaringClass);
            }

            /** @var non-empty-list<non-empty-string> $branch */
            return [$branch];
        }

        \assert($type instanceof \ReflectionNamedType);
        $branches = [[self::reflectedAtom($type, $declaringClass)]];

        if ($type->allowsNull() && !in_array($type->getName(), ['mixed', 'null'], strict: true)) {
            $branches[] = ['null'];
        }

        return $branches;
    }

    /**
     * @return non-empty-string
     */
    private static function reflectedAtom(\ReflectionNamedType $type, ?\ReflectionClass $declaringClass): string
    {
        $name = $type->getName();

        // Reflection resolves `self` in an interface to that interface's FQCN.
        // Keep its origin so generated `static` can satisfy it without making
        // two different target interfaces look like the same return type.
        if ($declaringClass instanceof \ReflectionClass
            && ($name === 'self' || (!$type->isBuiltin() && $name === $declaringClass->getName()))
        ) {
            return self::TARGET_SELF . $declaringClass->getName();
        }

        if ($name === 'static') {
            return self::GENERATED_STATIC;
        }

        if ($name === 'parent' && $declaringClass instanceof \ReflectionClass) {
            $parent = $declaringClass->getParentClass();

            return $parent === false ? 'parent' : '\\' . $parent->getName();
        }

        return $type->isBuiltin() ? $name : '\\' . $name;
    }

    /**
     * Splits on a top-level `|` and an intersection's `&`, with a DNF branch
     * unwrapped after the split — applied to a whole type, unwrapping would
     * cut `(A&B)|(C&D)` in half.
     *
     * @return non-empty-list<non-empty-list<non-empty-string>>
     */
    private static function branchesOf(string $rendered): array
    {
        if (str_starts_with($rendered, '?')) {
            return [...self::branchesOf(substr($rendered, 1)), ['null']];
        }

        $branches = [];
        $depth = 0;
        $buffer = '';

        foreach (str_split($rendered) as $character) {
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
        $parsed = [];

        foreach ($branches as $branch) {
            $branch = trim($branch);

            if (str_starts_with($branch, '(') && str_ends_with($branch, ')')) {
                $branch = substr($branch, 1, -1);
            }

            $atoms = array_values(array_filter(
                array_map(trim(...), explode('&', $branch)),
                static fn(string $atom): bool => $atom !== '',
            ));

            if ($atoms !== []) {
                $parsed[] = $atoms;
            }
        }

        // An empty string is no declared type at all.
        return $parsed === [] ? [['mixed']] : $parsed;
    }
}
