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

final class SignatureConflict extends \LogicException
{
}

/**
 * Minimal stand-in for the codegen pre-check: renders each declaration and
 * requires one target's method to be compatible with every other declaration
 * of the same name. The real implementation compares structured signatures;
 * for the spike a rendered normal form is enough to prove detectability.
 *
 * @param list<class-string> $targets
 */
function assertCompatibleTargets(array $targets): void
{
    /** @var array<string, array{class-string, string}> $seen */
    $seen = [];

    foreach ($targets as $target) {
        $reflection = new \ReflectionClass($target);

        foreach ($reflection->getMethods() as $method) {
            $signature = render($method);

            if (isset($seen[$method->getName()]) && $seen[$method->getName()][1] !== $signature) {
                throw new SignatureConflict(sprintf(
                    'Method %s() is declared incompatibly: %s::%s vs %s::%s',
                    $method->getName(),
                    $seen[$method->getName()][0],
                    $seen[$method->getName()][1],
                    $target,
                    $signature,
                ));
            }

            $seen[$method->getName()] = [$target, $signature];
        }
    }
}

function render(\ReflectionMethod $method): string
{
    $params = array_map(
        static fn (\ReflectionParameter $p): string => (string) $p->getType() . ($p->isVariadic() ? '...' : '') . ($p->isPassedByReference() ? '&' : ''),
        $method->getParameters(),
    );

    return sprintf('(%s): %s', implode(', ', $params), (string) $method->getReturnType());
}

$conflict = expectThrows(
    static fn () => assertCompatibleTargets([WriterA::class, WriterB::class]),
    SignatureConflict::class,
    'conflicting `write(int)` vs `write(string)` is detected before eval',
);
assertTrue(
    str_contains($conflict->getMessage(), 'WriterA') && str_contains($conflict->getMessage(), 'WriterB'),
    'the error names both conflicting targets',
);

assertCompatibleTargets([WriterA::class, WriterC::class]);
assertTrue(true, 'identical declarations across targets are compatible');

$multiCode = <<<'PHP'
namespace Understudy\Spikes\DnfMultiTarget;

final class MultiDouble implements WriterA, WriterC
{
    public function write(int|ArgumentMatcher $chunk): void
    {
        Runtime::dispatch($this, __FUNCTION__, [$chunk]);
    }
}
PHP;

eval($multiCode);

$multi = new MultiDouble();
assertTrue($multi instanceof WriterA && $multi instanceof WriterC, 'compatible multi-target double implements both interfaces');

$multi->write(1);
assertSame(Runtime::$log[array_key_last(Runtime::$log)][1], [1], 'multi-target dispatch works');
