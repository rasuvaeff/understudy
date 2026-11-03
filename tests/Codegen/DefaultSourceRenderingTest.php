<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Codegen;

use Rasuvaeff\Understudy\Codegen\TargetUnifier;
use Rasuvaeff\Understudy\Exception\UnsupportedTarget;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * The default a parameter is rendered with, read from where it surfaces: two
 * targets that disagree about it. The refusal quotes the first target's default
 * as the generated class would carry it, which is the only place that text is
 * visible — comparing the value an omitted argument ends up with cannot tell a
 * rendered constant from a rendered literal, since both evaluate to the same.
 */
#[Test]
#[Covers(TargetUnifier::class)]
#[Covers(UnsupportedTarget::class)]
final class DefaultSourceRenderingTest
{
    private const string NAMESPACE = 'Rasuvaeff\\Understudy\\Tests\\Codegen\\Rendered';

    #[DataProvider('defaultProvider')]
    public function aDefaultIsRenderedTheWayTheGeneratedClassCarriesIt(string $suffix, string $declarations, string $expected): void
    {
        $namespace = self::NAMESPACE . '\\' . $suffix;

        if (!interface_exists($namespace . '\\Z', autoload: false)) {
            eval('namespace ' . $namespace . '; ' . $declarations . ' interface Z { public function f(int $x = 99999); }');
        }

        try {
            TargetUnifier::unify([new \ReflectionClass($namespace . '\\A'), new \ReflectionClass($namespace . '\\Z')]);
            Assert::fail('two different defaults must be refused');
        } catch (UnsupportedTarget $refused) {
            Assert::same($refused->getMessage(), sprintf(
                "Cannot create one understudy for these targets: method `f()` has no implementation that satisfies all of them.\n"
                . "  `%s\\A::f()` defaults parameter #1 to %s\n"
                . '  `%s\\Z::f()` defaults it to 99999',
                $namespace,
                str_replace('{ns}', $namespace, $expected),
                $namespace,
            ));
        }
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function defaultProvider(): iterable
    {
        yield 'a constant of the declaring interface' => [
            'SelfConstant',
            'interface A { const LIMIT = 3; public function f(int $x = self::LIMIT): void; }',
            '\\{ns}\\A::LIMIT',
        ];

        yield 'the keyword in any case' => [
            'SelfConstantCased',
            'interface A { const LIMIT = 3; public function f(int $x = Self::LIMIT): void; }',
            '\\{ns}\\A::LIMIT',
        ];

        yield 'a constant of another class' => [
            'OtherClassConstant',
            'class Other { const OT = 5; } interface A { public function f(int $x = Other::OT): void; }',
            '\\{ns}\\Other::OT',
        ];

        yield 'a constant of another interface' => [
            'OtherInterfaceConstant',
            'interface Base { const B = 2; } interface A { public function f(int $x = Base::B): void; }',
            '\\{ns}\\Base::B',
        ];

        yield 'an enum case' => [
            'EnumCase',
            'enum E: string { case A = "a"; } interface A { public function f(E $x = E::A): void; }',
            '\\{ns}\\E::A',
        ];

        yield 'a constant of the parent, named through the parent' => [
            'ParentConstant',
            'class Base { const B = 1; } abstract class A extends Base { abstract public function f(int $x = parent::B): void; }',
            '\\{ns}\\Base::B',
        ];

        yield 'the parent keyword in any case' => [
            'ParentConstantCased',
            'class Base { const B = 1; } abstract class A extends Base { abstract public function f(int $x = PARENT::B): void; }',
            '\\{ns}\\Base::B',
        ];

        yield 'a protected constant of the parent is its value' => [
            'ProtectedParentConstant',
            'class Base { protected const B = 1; } abstract class A extends Base { abstract public function f(int $x = parent::B): void; }',
            '1',
        ];

        yield 'a protected constant of the class itself is its value' => [
            'ProtectedSelfConstant',
            'abstract class A { protected const B = 1; abstract public function f(int $x = self::B): void; }',
            '1',
        ];

        yield 'a global constant is its value' => [
            'GlobalConstant',
            'interface A { public function f(int $x = PHP_INT_MAX): void; }',
            '9223372036854775807',
        ];

        yield 'a new expression is its source' => [
            'NewExpression',
            'class Foo { public function __construct(public int $n = 0) {} } interface A { public function f(Foo $x = new Foo(3)): void; }',
            'new \\{ns}\\Foo(3)',
        ];

        yield 'an expression over a constant is its value' => [
            'ConstantExpression',
            'interface A { const LIMIT = 3; public function f(int $x = self::LIMIT + 1): void; }',
            '4',
        ];

        yield 'an array of scalars is its value' => [
            'ArrayDefault',
            'interface A { public function f(array $x = [1, \'a\' => true, 2 => null]): void; }',
            "array (\n  0 => 1,\n  'a' => true,\n  2 => NULL,\n)",
        ];

        yield 'null is written in lower case' => [
            'NullDefault',
            'interface A { public function f(?int $x = null): void; }',
            'null',
        ];
    }
}
