<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Codegen;

use Rasuvaeff\Understudy\Exception\UnsupportedTarget;
use Rasuvaeff\Understudy\Runtime\Absent;

/**
 * Unifies one parameter position — or the variadic tail — across the
 * same-named declarations of every target.
 *
 * Parameters are contravariant, so the rendered type is the union of what the
 * targets accept; only a by-reference mismatch and two disagreeing defaults
 * are conflicts.
 *
 * @internal
 */
final class ParameterUnifier
{
    /** Used when a target declares a variadic without a usable name. */
    private const string DEFAULT_TAIL_NAME = 'rest';

    private const string ABSENT = '\\' . Absent::class;
    private const string ABSENT_DEFAULT = self::ABSENT . '::Argument';

    private function __construct() {}

    /**
     * The earliest position at which any target declares a variadic, or null
     * when none does.
     *
     * @param non-empty-list<\ReflectionMethod> $declarations
     *
     * @return int<0, max>|null
     */
    public static function firstVariadicPosition(array $declarations): ?int
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
    public static function variadicTail(string $name, array $declarations, int $from): array
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
                $rendered = TypeRenderer::parameterType($parameter->getType(), $parameter->getDeclaringClass());

                if ($rendered === '') {
                    $untyped = true;
                } else {
                    foreach (DnfType::parse($rendered)->renderedBranches() as $part) {
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
                        TargetUnifier::describe($byReferenceSource) . ' takes its variadic tail by ' . ($byReference ? 'reference' : 'value'),
                        TargetUnifier::describe($declaration) . ' takes it by ' . ($parameter->isPassedByReference() ? 'reference' : 'value'),
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
     * @return array{rendered: non-empty-string, name: non-empty-string, byReference: bool,
     *               optional: bool, default: \ReflectionParameter|null}
     */
    public static function unify(string $name, array $declarations, int $position): array
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
        $defaultDeclaredBy = null;
        $defaultParameter = null;

        foreach ($declarations as $declaration) {
            $parameter = $declaration->getParameters()[$position] ?? null;

            if ($parameter === null) {
                $declaredEverywhere = false;

                continue;
            }

            $rendered = TypeRenderer::parameterType($parameter->getType(), $parameter->getDeclaringClass());

            if ($rendered === '') {
                $untyped = true;
            } else {
                foreach (DnfType::parse($rendered)->renderedBranches() as $part) {
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
                    TargetUnifier::describe($firstToDeclare) . ' takes parameter #' . ($position + 1) . ' by ' . ($byReference ? 'reference' : 'value'),
                    TargetUnifier::describe($declaration) . ' takes it by ' . ($parameter->isPassedByReference() ? 'reference' : 'value'),
                );
            }

            $byReference = $parameter->isPassedByReference();
            $requiredEverywhere = $requiredEverywhere && !$parameter->isOptional();

            if ($parameter->isDefaultValueAvailable()) {
                // Two targets disagreeing about the default is the one case
                // the unifier cannot answer faithfully. Keeping either would
                // make the other's omitted argument log a value it never
                // received. A double that answers with a different default
                // than the real thing is a test that passes for the wrong
                // reason.
                //
                // The default itself is never carried by the generated class
                // — dispatch reads it from the declaring parameter — so a
                // single declaration needs no rendering at all, and the
                // comparison key is computed only when a second one arrives.
                //
                // The FIRST target to declare one is the one named against
                // the disagreeing target, so a conflict among three reads as
                // one pair and not as whichever two happened to be adjacent.
                if (!$defaultParameter instanceof \ReflectionParameter) {
                    $defaultDeclaredBy = $declaration;
                    $defaultParameter = $parameter;
                } else {
                    \assert($defaultDeclaredBy instanceof \ReflectionMethod);
                    $declaredDefault = DefaultRenderer::render($defaultParameter, $name);
                    $declared = DefaultRenderer::render($parameter, $name);

                    if ($declaredDefault !== $declared) {
                        throw UnsupportedTarget::signatureConflict(
                            $name,
                            TargetUnifier::describe($defaultDeclaredBy) . ' defaults parameter #' . ($position + 1) . ' to ' . $declaredDefault,
                            TargetUnifier::describe($declaration) . ' defaults it to ' . $declared,
                        );
                    }
                }
            }
        }

        $optional = !$declaredEverywhere || !$requiredEverywhere;

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

                // Exactly where the matcher branch goes, for the same reason
                // and under the same exemptions: `mixed`, `object` and an
                // untyped parameter admit the sentinel already, and appending
                // it to `object` would be the redundant union PHP refuses.
                //
                // Every parameter carries it, optional ones included: the
                // sentinel is how a specification says nothing about a
                // parameter it did not spell, and a materialized default
                // in its place would make the omission indistinguishable
                // from spelling the default value.
                $types[self::ABSENT] = true;
            }

            $type = implode('|', array_keys($types));

            // A position optional only because another target does not
            // declare it has no contract default to put back, so dispatch
            // fills it with `null` and the type has to admit one. A parameter
            // that declares its own default needs nothing here: an implicitly
            // nullable one is reported nullable by Reflection already, and
            // this branch is the only place the union is real — `mixed|null`
            // is not a type PHP accepts at all.
            if ($optional && $defaultParameter === null && !isset($types['null'])) {
                $type .= '|null';
            }
        }

        // Every parameter defaults to the sentinel, so a specification may
        // physically pass fewer arguments than the method declares — and, for
        // an optional parameter, so that leaving it unspelled stays visible as
        // such instead of arriving as the contract's default value.
        //
        // Keeping an omitted argument observable is still the rule for a real
        // call: `tag('alpha')` must log the same arguments as `tag('alpha', 1)`,
        // or the two would verify as different calls. Dispatch materializes the
        // declared default for a sentinel that survives into one, and raises
        // the `ArgumentCountError` PHP would have raised where the contract
        // declares no default at all.
        $rendered = trim(sprintf(
            '%s %s$%s = %s',
            $type,
            $byReference === true ? '&' : '',
            $parameterName,
            self::ABSENT_DEFAULT,
        ));
        \assert($rendered !== '');

        return [
            'rendered' => $rendered,
            'name' => $parameterName,
            'byReference' => $byReference === true,
            'optional' => $optional,
            'default' => $defaultParameter,
        ];
    }
}
