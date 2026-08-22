<?php

/**
 * Spike 04 — DNF parameter widening and multi-target conflict detection.
 *
 * Promises under test (plan §4.2, §5.1):
 * - an intersection parameter `A&B` widens to `(A&B)|ArgumentMatcher` and
 *   the eval'd double compiles and runs on PHP 8.3+;
 * - an object implementing both interfaces passes, a matcher passes, an
 *   object implementing only one interface is rejected natively;
 * - multi-target doubles (`for(A::class, B::class)`) with conflicting
 *   method signatures are DETECTED via Reflection before eval, with both
 *   signatures reported; compatible targets generate fine.
 */

declare(strict_types=1);

namespace Understudy\Spikes\DnfMultiTarget;

use function Understudy\Spikes\assertSame;
use function Understudy\Spikes\assertTrue;
use function Understudy\Spikes\expectThrows;

require __DIR__ . '/../lib.php';

final class ArgumentMatcher
{
}

interface Countable2
{
    public function count(): int;
}

interface Named
{
    public function name(): string;
}

interface Consumer
{
    public function consume(Countable2&Named $target): string;
}

final class Runtime
{
    /** @var list<array{string, array<mixed>}> */
    public static array $log = [];

    public static function dispatch(object $double, string $method, array $args): string
    {
        self::$log[] = [$method, $args];

        return $method;
    }
}

// --- DNF widening ---------------------------------------------------------

$code = <<<'PHP'
namespace Understudy\Spikes\DnfMultiTarget;

final class ConsumerDouble implements Consumer
{
    public function consume((Countable2&Named)|ArgumentMatcher $target): string
    {
        return Runtime::dispatch($this, __FUNCTION__, [$target]);
    }
}
PHP;

eval($code);

echo "04-dnf-multitarget\n";

$double = new ConsumerDouble();
assertTrue($double instanceof Consumer, 'eval\'d double with `(A&B)|ArgumentMatcher` satisfies the interface');

$both = new class implements Countable2, Named {
    public function count(): int
    {
        return 0;
    }

    public function name(): string
    {
        return 'x';
    }
};

$double->consume($both);
assertTrue(Runtime::$log[0][1][0] === $both, 'object implementing both interfaces passes the DNF branch');

$double->consume(new ArgumentMatcher());
assertTrue(Runtime::$log[1][1][0] instanceof ArgumentMatcher, 'matcher passes the union branch');

$onlyNamed = new class implements Named {
    public function name(): string
    {
        return 'y';
    }
};

expectThrows(
    static fn (): string => $double->consume($onlyNamed),
    \TypeError::class,
    'object implementing only one interface is rejected natively',
);

// --- Multi-target conflict detection ---------------------------------------

interface WriterA
{
    public function write(int $chunk): void;
}

interface WriterB
{
    public function write(string $chunk): void;
}

interface WriterC
{
    public function write(int $chunk): void;
}

interface ArityA
{
    public function emit(int $a): void;
}

interface ArityB
{
    public function emit(int $a, int $b): void;
}

interface ReaderInt
{
    public function read(): int;
}

interface ReaderString
{
    public function read(): string;
}

interface FeederByRef
{
    public function feed(int &$slot): void;
}

interface FeederByValue
{
    public function feed(int $slot): void;
}

final class SignatureConflict extends \LogicException
{
}

/**
 * Codegen pre-check: multi-target compatibility is UNIFICATION, not equality.
 *
 * PHP parameters are contravariant, so `write(int)` and `write(string)` share
 * a valid implementation — `write(int|string)`. Rejecting them on rendered
 * equality would refuse a doublable pair. A conflict exists only where no
 * single declaration can satisfy every target:
 *
 * - return types are covariant, so they must agree (`int` vs `string` cannot
 *   be satisfied — verified against PHP below);
 * - by-reference-ness must match exactly;
 * - parameter types unify into a union;
 * - a parameter missing from (or optional in) any target must be optional.
 *
 * @param list<class-string> $targets
 *
 * @return array<string, string> method name => generated parameter list
 */
function unifyTargets(array $targets): array
{
    /** @var array<string, list<array{class-string, \ReflectionMethod}>> $byName */
    $byName = [];

    foreach ($targets as $target) {
        foreach ((new \ReflectionClass($target))->getMethods() as $method) {
            $byName[$method->getName()][] = [$target, $method];
        }
    }

    $signatures = [];

    foreach ($byName as $name => $declarations) {
        $signatures[$name] = unifyMethod($name, $declarations);
    }

    return $signatures;
}

/**
 * @param non-empty-list<array{class-string, \ReflectionMethod}> $declarations
 */
function unifyMethod(string $name, array $declarations): string
{
    /** @var array<string, list<class-string>> $returns */
    $returns = [];

    foreach ($declarations as [$class, $method]) {
        $returns[(string) $method->getReturnType() ?: 'mixed'][] = $class;
    }

    if (count($returns) > 1) {
        throw new SignatureConflict(sprintf(
            'Method %s() has irreconcilable return types: %s',
            $name,
            implode(' vs ', array_map(
                static fn (string $type, array $classes): string => implode('+', $classes) . ' declares `: ' . $type . '`',
                array_keys($returns),
                $returns,
            )),
        ));
    }

    $arity = max(array_map(
        static fn (array $d): int => $d[1]->getNumberOfParameters(),
        $declarations,
    ));

    $parameters = [];

    for ($position = 0; $position < $arity; $position++) {
        /** @var array<string, true> $types */
        $types = [];
        /** @var array<string, list<class-string>> $byReference */
        $byReference = [];
        $requiredEverywhere = true;

        foreach ($declarations as [$class, $method]) {
            $parameter = $method->getParameters()[$position] ?? null;

            if ($parameter === null) {
                $requiredEverywhere = false;

                continue;
            }

            foreach (explode('|', (string) $parameter->getType()) as $type) {
                $types[ltrim($type, '?')] = true;
            }

            if ($parameter->allowsNull()) {
                $types['null'] = true;
            }

            $byReference[$parameter->isPassedByReference() ? 'by-reference' : 'by-value'][] = $class;
            $requiredEverywhere = $requiredEverywhere && !$parameter->isOptional();
        }

        if (count($byReference) > 1) {
            throw new SignatureConflict(sprintf(
                'Method %s() parameter #%d is %s',
                $name,
                $position + 1,
                implode(' but ', array_map(
                    static fn (string $mode, array $classes): string => $mode . ' in ' . implode('+', $classes),
                    array_keys($byReference),
                    $byReference,
                )),
            ));
        }

        $reference = array_key_first($byReference) === 'by-reference' ? '&' : '';
        $types['ArgumentMatcher'] = true;

        $parameters[] = sprintf(
            '%s %s$p%d%s',
            implode('|', array_keys($types)),
            $reference,
            $position,
            $requiredEverywhere ? '' : ' = null',
        );
    }

    return implode(', ', $parameters);
}

$signatures = unifyTargets([WriterA::class, WriterB::class]);
assertSame(
    $signatures['write'],
    'int|string|ArgumentMatcher $p0',
    'contravariant parameters unify into a union instead of being rejected',
);

// The unified declaration must really satisfy both interfaces.
eval(sprintf(
    'namespace Understudy\Spikes\DnfMultiTarget; final class UnifiedDouble implements WriterA, WriterB { public function write(%s): void { Runtime::dispatch($this, __FUNCTION__, [$p0]); } }',
    $signatures['write'],
));

$unified = new UnifiedDouble();
assertTrue(
    $unified instanceof WriterA && $unified instanceof WriterB,
    'the unified declaration satisfies BOTH `write(int)` and `write(string)`',
);

$unified->write(1);
$unified->write('one');
assertSame(Runtime::$log[array_key_last(Runtime::$log)][1], ['one'], 'unified multi-target dispatch works for either branch');

assertSame(
    unifyTargets([ArityA::class, ArityB::class])['emit'],
    'int|ArgumentMatcher $p0, int|ArgumentMatcher $p1 = null',
    'a parameter absent from one target becomes optional',
);

assertSame(
    unifyTargets([WriterA::class, WriterC::class])['write'],
    'int|ArgumentMatcher $p0',
    'identical declarations unify to themselves',
);

$conflict = expectThrows(
    static fn () => unifyTargets([ReaderInt::class, ReaderString::class]),
    SignatureConflict::class,
    'irreconcilable return types are rejected before eval (covariance leaves no common subtype)',
);
assertTrue(
    str_contains($conflict->getMessage(), 'ReaderInt') && str_contains($conflict->getMessage(), 'ReaderString'),
    'the error names both conflicting targets',
);

$conflict = expectThrows(
    static fn () => unifyTargets([FeederByRef::class, FeederByValue::class]),
    SignatureConflict::class,
    'by-reference mismatch across targets is rejected before eval',
);
assertTrue(
    str_contains($conflict->getMessage(), 'by-reference') && str_contains($conflict->getMessage(), 'by-value'),
    'the error explains which mode each target declares',
);
