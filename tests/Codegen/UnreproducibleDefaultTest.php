<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Codegen;

use Rasuvaeff\Understudy\Codegen\TargetUnifier;
use Rasuvaeff\Understudy\Exception\UnsupportedTarget;
use Rasuvaeff\Understudy\Understudy;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

/**
 * A default the generated class cannot carry is a refusal, never a quiet
 * `null`: a double that answered with another default than the real thing
 * would let a test pass for the wrong reason.
 *
 * The contracts are built by eval so that each one names exactly the shape it
 * is about, in a namespace of its own.
 */
#[Test]
#[Covers(TargetUnifier::class)]
#[Covers(UnsupportedTarget::class)]
final class UnreproducibleDefaultTest
{
    #[AfterTest]
    public function tearDown(): void
    {
        Understudy::reset();
    }

    public function aNewExpressionNamingSelfIsRefused(): void
    {
        $this->declare(
            'SelfNew',
            'class Foo { public function __construct(public int $n = 0) {} }'
            . ' interface Contract { const LIMIT = 3; public function m(Foo $x = new Foo(self::LIMIT)): void; }',
        );

        Expect::exception(UnsupportedTarget::class)->withMessage(
            'Cannot create an understudy for `' . self::NAMESPACE . '\\SelfNew\\Contract`: '
            . 'the default of `$x` in `m()` is `new \\' . self::NAMESPACE . '\\SelfNew\\Foo(self::LIMIT)`, '
            . 'which resolves `self`, `static` or `parent` against the declaring class. '
            . 'The generated double is a different class, so the value cannot be reproduced. '
            . 'Give the parameter a default the double can build, or double an interface.',
        );

        Understudy::for(self::NAMESPACE . '\\SelfNew\\Contract');
    }

    public function aNewExpressionNamingParentIsRefused(): void
    {
        $this->declare(
            'ParentNew',
            'class Foo { public function __construct(public int $n = 0) {} }'
            . ' class Base { const B = 1; }'
            . ' class Contract extends Base { public function m(Foo $x = new Foo(parent::B)): void {} }',
        );

        Expect::exception(UnsupportedTarget::class)->withMessageContaining(
            'Cannot create an understudy for `' . self::NAMESPACE . '\\ParentNew\\Contract`: '
            . 'the default of `$x` in `m()` is `new \\' . self::NAMESPACE . '\\ParentNew\\Foo(parent::B)`, '
            . 'which resolves `self`, `static` or `parent` against the declaring class.',
        );

        Understudy::for(self::NAMESPACE . '\\ParentNew\\Contract');
    }

    public function aGlobalConstantHoldingAnObjectIsRefused(): void
    {
        $this->declare(
            'ObjectConstant',
            'const OBJ = new \\stdClass(); interface Contract { public function m(object $o = OBJ): void; }',
        );

        Expect::exception(UnsupportedTarget::class)->withMessage(
            'Cannot create an understudy for `' . self::NAMESPACE . '\\ObjectConstant\\Contract`: '
            . 'the default of `$o` in `m()` holds an object that cannot be written back as source. '
            . 'Give the parameter a scalar, array or enum default, or double an interface.',
        );

        Understudy::for(self::NAMESPACE . '\\ObjectConstant\\Contract');
    }

    public function anObjectBuriedInAnArrayConstantIsRefusedToo(): void
    {
        $this->declare(
            'ObjectInList',
            'const LST = [new \\stdClass()]; interface Contract { public function m(array $o = LST): void; }',
        );

        Expect::exception(UnsupportedTarget::class)->withMessageContaining('holds an object that cannot be written back as source');

        Understudy::for(self::NAMESPACE . '\\ObjectInList\\Contract');
    }

    private const string NAMESPACE = 'Rasuvaeff\\Understudy\\Tests\\Codegen\\Evald';

    private function declare(string $suffix, string $declarations): void
    {
        $namespace = self::NAMESPACE . '\\' . $suffix;

        if (!interface_exists($namespace . '\\Contract', autoload: false) && !class_exists($namespace . '\\Contract', autoload: false)) {
            eval('namespace ' . $namespace . '; ' . $declarations);
        }
    }
}
