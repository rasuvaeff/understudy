<?php

/**
 * Spike 09 — a default is evaluated in its declaring class, read at dispatch.
 *
 * Promises under test (what lifting the "default cannot be reproduced"
 * refusal rests on):
 * - `ReflectionParameter::getDefaultValue()` resolves `self::` and `parent::`
 *   inside a `new` initializer against the class that DECLARED the
 *   parameter, even when asked through a reflection of an overriding class;
 * - each call evaluates the initializer again — one object per call, the way
 *   PHP itself runs a `new` default;
 * - a global constant holding an object is read back as that object;
 * - an override whose parameter defaults to an enum sentinel stays
 *   signature-compatible with a parent whose default is `new Foo(self::X)`,
 *   which is what every generated method already declares.
 */

declare(strict_types=1);

namespace Understudy\Spikes\DefaultAtDispatch;

use function Understudy\Spikes\assertSame;
use function Understudy\Spikes\assertTrue;

require __DIR__ . '/../lib.php';

final class Foo
{
    public function __construct(public int $n = 0) {}
}

interface Contract
{
    public const LIMIT = 3;

    public function m(Foo $x = new Foo(self::LIMIT)): int;
}

class Base
{
    public const B = 7;
}

abstract class Child extends Base
{
    abstract public function m(Foo $x = new Foo(parent::B)): int;
}

const OBJ = new Foo(11);

interface Holder
{
    public function m(object $o = OBJ): int;
}

enum Absent
{
    case Argument;
}

final class Generated implements Contract
{
    public function m(Foo|Absent $x = Absent::Argument): int
    {
        return 0;
    }
}

$fromInterface = new \ReflectionParameter([Contract::class, 'm'], 'x');
$first = $fromInterface->getDefaultValue();
assertTrue($first instanceof Foo, 'an interface default builds its object');
assertSame($first->n, 3, 'self:: in an interface default resolves to the interface');
assertTrue($fromInterface->getDefaultValue() !== $first, 'each read evaluates the initializer again');

$throughOverride = (new \ReflectionMethod(Generated::class, 'm'))->getPrototype()->getParameters()[0];
assertSame($throughOverride->getDefaultValue()->n, 3, 'the prototype reached from the override still resolves self:: to the interface');

$fromChild = new \ReflectionParameter([Child::class, 'm'], 'x');
assertSame($fromChild->getDefaultValue()->n, 7, 'parent:: in a default resolves to the declaring class parent');

$held = (new \ReflectionParameter([Holder::class, 'm'], 'o'))->getDefaultValue();
assertTrue($held === OBJ, 'a global constant holding an object is read back as that object');

assertSame((new Generated())->m(), 0, 'a sentinel-defaulted override is signature-compatible');
