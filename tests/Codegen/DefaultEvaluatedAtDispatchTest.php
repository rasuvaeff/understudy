<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Codegen;

use Rasuvaeff\Understudy\Codegen\DefaultRenderer;
use Rasuvaeff\Understudy\Codegen\TargetUnifier;
use Rasuvaeff\Understudy\Exception\UnsupportedTarget;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

/**
 * A default the generated class could never carry as source — `new Foo(self::X)`,
 * an object held by a constant — used to refuse the target. Since #123 the
 * generated class carries none: dispatch reads the default from the declaring
 * parameter, in the declaring class's scope, so these targets are doubled and
 * an omitted argument logs exactly what the real method would have received.
 *
 * What still refuses is two targets disagreeing about a default, and two
 * `self::` defaults in different classes are two different defaults.
 *
 * The contracts are built by eval so that each one names exactly the shape it
 * is about, in a namespace of its own. `spikes/09-default-at-dispatch` pins
 * the language claims on 8.3/8.4/8.5.
 */
#[Test]
#[Covers(TargetUnifier::class)]
#[Covers(DefaultRenderer::class)]
#[Covers(UnsupportedTarget::class)]
final class DefaultEvaluatedAtDispatchTest
{
    private const string NAMESPACE = 'Rasuvaeff\\Understudy\\Tests\\Codegen\\Evald';

    #[AfterTest]
    public function tearDown(): void
    {
        Understudy::reset();
    }

    public function aNewExpressionNamingSelfIsEvaluatedInTheDeclaringInterface(): void
    {
        $this->declare(
            'SelfNew',
            'class Foo { public function __construct(public int $n = 0) {} }'
            . ' interface Contract { const LIMIT = 3; public function m(Foo $x = new Foo(self::LIMIT)): void; }',
        );

        $double = Understudy::for(self::NAMESPACE . '\\SelfNew\\Contract');
        $double->m();
        $double->m();

        $calls = Understudy::calls(static fn() => $double->m());
        Assert::same(\count($calls), 2);
        Assert::same($this->property($calls[0]->args[0], 'n'), 3);
        Assert::true($calls[0]->args[0] !== $calls[1]->args[0]);
    }

    public function aNewExpressionNamingParentIsEvaluatedAgainstTheDeclaringParent(): void
    {
        $this->declare(
            'ParentNew',
            'class Foo { public function __construct(public int $n = 0) {} }'
            . ' class Base { const B = 1; }'
            . ' class Contract extends Base { public function m(Foo $x = new Foo(parent::B)): void {} }',
        );

        $double = Understudy::for(self::NAMESPACE . '\\ParentNew\\Contract');
        $double->m();

        Assert::same($this->property(Understudy::lastCall(static fn() => $double->m())?->args[0], 'n'), 1);
    }

    public function aGlobalConstantHoldingAnObjectIsPassedOn(): void
    {
        $this->declare(
            'ObjectConstant',
            'const OBJ = new \\stdClass(); interface Contract { public function m(object $o = OBJ): void; }',
        );

        $double = Understudy::for(self::NAMESPACE . '\\ObjectConstant\\Contract');
        $double->m();

        Assert::same(
            Understudy::lastCall(static fn() => $double->m())?->args[0],
            \constant(self::NAMESPACE . '\\ObjectConstant\\OBJ'),
        );
    }

    public function anObjectBuriedInAnArrayConstantIsPassedOn(): void
    {
        $this->declare(
            'ObjectInList',
            'const LST = [new \\stdClass()]; interface Contract { public function m(array $o = LST): void; }',
        );

        $double = Understudy::for(self::NAMESPACE . '\\ObjectInList\\Contract');
        $double->m();

        Assert::same(
            Understudy::lastCall(static fn() => $double->m())?->args[0],
            \constant(self::NAMESPACE . '\\ObjectInList\\LST'),
        );
    }

    public function twoTargetsWithTheSameObjectDefaultCompose(): void
    {
        $this->declare(
            'SameNew',
            'class Foo { public function __construct(public int $n = 0) {} }'
            . ' interface Contract { public function m(Foo $x = new Foo(2)): void; }'
            . ' interface Other { public function m(Foo $x = new Foo(2)): void; }',
        );

        $double = Understudy::for(self::NAMESPACE . '\\SameNew\\Contract', self::NAMESPACE . '\\SameNew\\Other');
        $double->m();

        Assert::same($this->property(Understudy::lastCall(static fn() => $double->m())?->args[0], 'n'), 2);
    }

    public function selfInTwoDeclaringClassesIsTwoDifferentDefaults(): void
    {
        $namespace = self::NAMESPACE . '\\TwoSelves';
        $this->declare(
            'TwoSelves',
            'class Foo { public function __construct(public int $n = 0) {} }'
            . ' interface Contract { const L = 1; public function m(Foo $x = new Foo(self::L)): void; }'
            . ' interface Other { const L = 1; public function m(Foo $x = new Foo(self::L)): void; }',
        );

        Expect::exception(UnsupportedTarget::class)->withMessage(
            "Cannot create one understudy for these targets: method `m()` has no implementation that satisfies all of them.\n"
            . '  `' . $namespace . '\\Contract::m()` defaults parameter #1 to new \\' . $namespace . '\\Foo(\\' . $namespace . "\\Contract::L)\n"
            . '  `' . $namespace . '\\Other::m()` defaults it to new \\' . $namespace . '\\Foo(\\' . $namespace . '\\Other::L)',
        );

        Understudy::for($namespace . '\\Contract', $namespace . '\\Other');
    }

    public function anObjectConstantComparedWithAnotherIsComparedBySource(): void
    {
        $namespace = self::NAMESPACE . '\\TwoConstants';
        $this->declare(
            'TwoConstants',
            'const ONE = new \\stdClass(); const TWO = new \\stdClass();'
            . ' interface Contract { public function m(object $o = ONE): void; }'
            . ' interface Other { public function m(object $o = TWO): void; }',
        );

        Expect::exception(UnsupportedTarget::class)->withMessageContaining('defaults parameter #1 to ' . $namespace . '\\ONE');

        Understudy::for($namespace . '\\Contract', $namespace . '\\Other');
    }

    private function property(mixed $object, string $name): mixed
    {
        Assert::true(\is_object($object));
        \assert(\is_object($object));

        return get_object_vars($object)[$name] ?? null;
    }

    private function declare(string $suffix, string $declarations): void
    {
        $namespace = self::NAMESPACE . '\\' . $suffix;

        if (!interface_exists($namespace . '\\Contract', autoload: false) && !class_exists($namespace . '\\Contract', autoload: false)) {
            eval('namespace ' . $namespace . '; ' . $declarations);
        }
    }
}
