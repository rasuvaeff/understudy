<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Codegen;

use Rasuvaeff\Understudy\Exception\UnsupportedTarget;

/**
 * Unifies the return types same-named methods declare across targets.
 *
 * Return types are covariant, so the generated override has to satisfy every
 * declaration at once: the narrowest declared type when one satisfies the
 * rest, an intersection of interface branches when none does, and a conflict
 * when no type can.
 *
 * @internal
 */
final class ReturnTypeUnifier
{
    private function __construct() {}

    /**
     * The return type a method declares, tentative ones included.
     *
     * PHP's own interfaces carry TENTATIVE return types — `Countable::count()`
     * is declared `: int`, and `getReturnType()` answers null for it, because
     * an implementation is still allowed to omit or widen the type until the
     * grace period ends. Reading only `getReturnType()` therefore made every
     * built-in contract look untyped, and the generated override declared
     * `mixed`: legal for `ArrayAccess::offsetGet` (tentatively `mixed` too),
     * and a deprecation notice for `Countable::count`, on a call as ordinary
     * as `Understudy::for(Repo::class, Countable::class)`. A future PHP turns
     * that notice into an error.
     *
     * Declaring the tentative type satisfies the contract exactly, so no
     * `#[\ReturnTypeWillChange]` is needed anywhere.
     */
    public static function declaredType(\ReflectionMethod $method): ?\ReflectionType
    {
        // hasReturnType() rather than a `??` over the two getters: the two are
        // mutually exclusive — a method with a tentative type has no declared
        // one — so either order of a coalesce behaves identically and the
        // swap is an equivalent mutant nothing can kill.
        return $method->hasReturnType()
            ? $method->getReturnType()
            : $method->getTentativeReturnType();
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
    public static function unify(string $name, array $declarations): string
    {
        foreach ($declarations as $candidate) {
            $satisfiesAll = true;

            foreach ($declarations as $required) {
                if (!self::satisfies($candidate, $required)) {
                    $satisfiesAll = false;

                    break;
                }
            }

            if ($satisfiesAll) {
                return TypeRenderer::returnType(self::declaredType($candidate), $candidate->getDeclaringClass());
            }
        }

        $intersection = self::intersectReturnTypes($declarations);

        if ($intersection !== null) {
            return $intersection;
        }

        [$left, $right] = self::conflictingReturnDeclarations($declarations);

        throw UnsupportedTarget::signatureConflict(
            $name,
            TargetUnifier::describe($left) . ' declares `: '
                . TypeRenderer::returnType(self::declaredType($left), $left->getDeclaringClass()) . '`',
            TargetUnifier::describe($right) . ' declares `: '
                . TypeRenderer::returnType(self::declaredType($right), $right->getDeclaringClass()) . '`',
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
        $branches = DnfType::fromReflection(self::declaredType($first), $first->getDeclaringClass())->branches;

        foreach (array_slice($declarations, 1) as $declaration) {
            $requiredBranches = DnfType::fromReflection(
                self::declaredType($declaration),
                $declaration->getDeclaringClass(),
            )->branches;
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

        return DnfType::of($branches)->render();
    }

    /**
     * @param non-empty-list<non-empty-string> $left
     * @param non-empty-list<non-empty-string> $right
     *
     * @return non-empty-list<non-empty-string>|null
     */
    private static function intersectReturnBranches(array $left, array $right): ?array
    {
        if (DnfType::branchSatisfies($left, $right)) {
            return $left;
        }

        if (DnfType::branchSatisfies($right, $left)) {
            return $right;
        }

        $atoms = [...$left, ...$right];

        foreach ($atoms as $atom) {
            if (!DnfType::atomIsInterface($atom)) {
                return null;
            }
        }

        $intersection = [];

        foreach ($atoms as $atom) {
            $redundant = false;

            foreach ($intersection as $position => $existing) {
                if (DnfType::atomSatisfies($atom, $existing)) {
                    unset($intersection[$position]);
                    continue;
                }

                if (DnfType::atomSatisfies($existing, $atom)) {
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
            if (DnfType::branchSatisfies($candidate, $existing)) {
                return;
            }

            if (DnfType::branchSatisfies($existing, $candidate)) {
                unset($branches[$position]);
            }
        }

        $branches[] = $candidate;
        $branches = array_values($branches);
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
                if (self::satisfies($left, $right) || self::satisfies($right, $left)) {
                    continue;
                }

                if (self::intersectReturnTypes([$left, $right]) === null) {
                    return [$left, $right];
                }
            }
        }

        return [$declarations[0], $declarations[array_key_last($declarations)]];
    }

    public static function satisfies(\ReflectionMethod $candidate, \ReflectionMethod $required): bool
    {
        return DnfType::fromReflection(self::declaredType($candidate), $candidate->getDeclaringClass())
            ->satisfies(DnfType::fromReflection(self::declaredType($required), $required->getDeclaringClass()));
    }
}
