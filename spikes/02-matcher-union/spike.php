<?php

/**
 * Spike 02 — contravariant `T|ArgumentMatcher` parameter widening.
 *
 * Promises under test (plan §4.2, §5.1):
 * - an override may widen every parameter type with `|ArgumentMatcher`
 *   (parameter contravariance) and still satisfy the interface;
 * - the double class is produced through eval, like the real codegen;
 * - `mixed` is not widened and still accepts a matcher instance;
 * - nullable parameters normalize into `T|ArgumentMatcher|null`;
 * - variadic and by-reference parameters accept matchers (by-ref via a
 *   variable, as PHP requires);
 * - scalar coercion follows the CALLING file's strict_types mode, not the
 *   defining file's: a weak-mode caller passing '5' reaches int(5), a
 *   strict-mode caller gets a TypeError.
 */

declare(strict_types=1);

namespace Understudy\Spikes\MatcherUnion;

use function Understudy\Spikes\assertSame;
use function Understudy\Spikes\assertTrue;
use function Understudy\Spikes\expectThrows;

require __DIR__ . '/../lib.php';

final class ArgumentMatcher
{
    public function __construct(public readonly string $kind = 'any')
    {
    }
}

interface Calc
{
    public function scale(int $factor): string;

    public function pick(?int $x): string;

    public function raw(mixed $x): string;

    public function many(int ...$ns): string;

    public function bump(int &$slot): string;
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

$code = <<<'PHP'
namespace Understudy\Spikes\MatcherUnion;

final class CalcDouble implements Calc
{
    public function scale(int|ArgumentMatcher $factor): string
    {
        return Runtime::dispatch($this, __FUNCTION__, [$factor]);
    }

    public function pick(int|ArgumentMatcher|null $x): string
    {
        return Runtime::dispatch($this, __FUNCTION__, [$x]);
    }

    public function raw(mixed $x): string
    {
        return Runtime::dispatch($this, __FUNCTION__, [$x]);
    }

    public function many(int|ArgumentMatcher ...$ns): string
    {
        return Runtime::dispatch($this, __FUNCTION__, $ns);
    }

    public function bump(int|ArgumentMatcher &$slot): string
    {
        return Runtime::dispatch($this, __FUNCTION__, [$slot]);
    }
}
PHP;

eval($code);

echo "02-matcher-union\n";

$double = new CalcDouble();
assertTrue($double instanceof Calc, 'eval\'d double with widened unions satisfies the interface');

$matcher = new ArgumentMatcher();

$double->scale(3);
$double->scale($matcher);
assertSame(Runtime::$log[0][1], [3], 'plain int passes the widened signature');
assertTrue(Runtime::$log[1][1][0] instanceof ArgumentMatcher, 'matcher passes as the union branch');

$double->pick(null);
assertSame(Runtime::$log[2][1], [null], 'nullable normalizes into `int|ArgumentMatcher|null`');

$double->raw($matcher);
assertTrue(Runtime::$log[3][1][0] instanceof ArgumentMatcher, '`mixed` is not widened yet accepts a matcher');

$double->many(1, $matcher, 2);
assertSame(count(Runtime::$log[4][1]), 3, 'variadic accepts mixed literals and matchers');

$slot = $matcher;
$double->bump($slot);
assertTrue(Runtime::$log[5][1][0] instanceof ArgumentMatcher, 'by-ref parameter accepts a matcher via a variable');

$int = 7;
$double->bump($int);
assertSame(Runtime::$log[6][1], [7], 'by-ref parameter still accepts a plain int');

expectThrows(
    static fn (): string => $double->scale('nope'),
    \TypeError::class,
    'non-numeric string is rejected by the native check even in the widened union',
);

// Coercion semantics follow the calling file.
$weak = require __DIR__ . '/weak_caller.php';
assertSame($weak($double), [5], "weak-mode caller: '5' coerces to int(5) through the union");

$strict = require __DIR__ . '/strict_caller.php';
expectThrows(
    static fn (): string => $strict($double),
    \TypeError::class,
    "strict-mode caller: '5' raises TypeError through the same union",
);
