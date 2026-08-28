<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\Understudy\Codegen\Blueprint;
use Rasuvaeff\Understudy\Codegen\DoubleFactory;
use Rasuvaeff\Understudy\Codegen\PropertySignature;
use Rasuvaeff\Understudy\Defaults\TypeDefaultResolver;
use Rasuvaeff\Understudy\Exception\ForgottenDouble;
use Rasuvaeff\Understudy\Exception\UnsupportedTarget;
use Rasuvaeff\Understudy\Runtime\DoubleState;
use Rasuvaeff\Understudy\Runtime\Runtime;
use Rasuvaeff\Understudy\Tests\Fixture\Hooks\Clock;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

/**
 * Interface-declared property hooks are rendered, not refused: this engine
 * generates the class source, so it can declare the property and put the
 * dispatcher inside the hook — which no `__get`-based library can, because
 * `__get` fires only for an inaccessible property.
 *
 * Every hooked contract here is built by eval: property hooks are a parse
 * error on 8.3, and a fixture file carrying them would take the whole suite
 * down there rather than skip a test.
 */
#[Test]
#[Covers(DoubleFactory::class)]
#[Covers(Blueprint::class)]
#[Covers(PropertySignature::class)]
#[Covers(Runtime::class)]
#[Covers(DoubleState::class)]
#[Covers(TypeDefaultResolver::class)]
#[Covers(UnsupportedTarget::class)]
#[Covers(ForgottenDouble::class)]
final class PropertyHooksTest
{
    #[AfterTest]
    public function tearDown(): void
    {
        Understudy::reset();
    }

    /**
     * @param non-empty-string $declaration sprintf template over namespace and class name
     *
     * @return class-string
     */
    private static function declare(string $suffix, string $declaration): string
    {
        $name = 'Hook' . $suffix . '_' . PHP_VERSION_ID;
        /** @var class-string $fqcn */
        $fqcn = __NAMESPACE__ . '\\' . $name;

        if (!interface_exists($fqcn, autoload: false) && !class_exists($fqcn, autoload: false)) {
            eval(sprintf($declaration, __NAMESPACE__, $name));
        }

        return $fqcn;
    }

    private static function onOldPhp(): bool
    {
        if (PHP_VERSION_ID >= 80400) {
            return false;
        }

        // Nothing to skip: the language cannot express a hooked property yet,
        // so the rendering path is provably unreachable there.
        Assert::false(method_exists(\ReflectionProperty::class, 'isAbstract'));

        return true;
    }

    // --- Rendering and loose defaults ---------------------------------------

    public function anInterfacePropertyIsRenderedAndReadsTheModeDefault(): void
    {
        if (self::onOldPhp()) {
            return;
        }

        $contract = self::declare('IfaceGet', 'namespace %s; interface %s { public string $name { get; } }');
        $double = Understudy::for($contract);

        Assert::instanceOf($double, $contract);
        Assert::same($double->name, '');
    }

    public function theRenderedPropertyIsVirtualAndSatisfiesTheContract(): void
    {
        if (self::onOldPhp()) {
            return;
        }

        $contract = self::declare('IfaceVirtual', 'namespace %s; interface %s { public int $count { get; } }');
        $double = Understudy::for($contract);
        $property = new \ReflectionProperty($double, 'count');

        Assert::true($property->isVirtual());
        Assert::false($property->isAbstract());
    }

    public function aGetSetPropertyRoundTripsWhatTheCodeUnderTestWrote(): void
    {
        if (self::onOldPhp()) {
            return;
        }

        $contract = self::declare('IfaceGetSet', 'namespace %s; interface %s { public string $name { get; set; } }');
        $double = Understudy::for($contract);

        Assert::same($double->name, '');

        $double->name = 'Ann';

        Assert::same($double->name, 'Ann');
    }

    /**
     * `null` written into a nullable property is a value someone wrote, not
     * "never written" — the read must answer it rather than fall back to the
     * default resolution.
     */
    public function aWrittenNullIsAValueNotAnAbsence(): void
    {
        if (self::onOldPhp()) {
            return;
        }

        $clock = Clock::class;
        $contract = self::declare(
            'IfaceNullable',
            'namespace %s; interface %s { public ?\\' . $clock . ' $clock { get; set; } }',
        );

        $double = Understudy::for($contract);
        Understudy::defaults(Clock::class, static fn(): object => Understudy::for(Clock::class));

        Assert::instanceOf($double->clock, Clock::class);

        $double->clock = null;

        Assert::null($double->clock);
    }

    public function anObjectTypedPropertyAnswersADepthOneDouble(): void
    {
        if (self::onOldPhp()) {
            return;
        }

        $contract = self::declare(
            'IfaceObject',
            'namespace %s; interface %s { public \\' . Clock::class . ' $clock { get; } }',
        );

        $double = Understudy::for($contract);

        Assert::instanceOf($double->clock, Clock::class);
    }

    public function aDefaultsRegistrationOutranksTheNestedDouble(): void
    {
        if (self::onOldPhp()) {
            return;
        }

        $contract = self::declare(
            'IfaceRegistered',
            'namespace %s; interface %s { public \\' . Clock::class . ' $clock { get; } }',
        );

        $pinned = Understudy::for(Clock::class);
        Understudy::defaults(Clock::class, static fn(): object => $pinned);

        Assert::same(Understudy::for($contract)->clock, $pinned);
    }

    public function anAbstractClassHookIsRenderedTheSameWay(): void
    {
        if (self::onOldPhp()) {
            return;
        }

        $contract = self::declare(
            'AbstractGet',
            'namespace %s; abstract class %s { abstract public string $tag { get; } }',
        );

        Assert::same(Understudy::for($contract)->tag, '');
    }

    /**
     * Exactly the declared hooks are rendered: a get-only contract gets no
     * `set`, and PHP itself refuses the write to the virtual property — a
     * write the contract never promised must not succeed silently.
     */
    public function aWriteToAGetOnlyPropertyIsRefusedByPhpItself(): void
    {
        if (self::onOldPhp()) {
            return;
        }

        $contract = self::declare('IfaceReadOnly', 'namespace %s; interface %s { public string $name { get; } }');
        $double = Understudy::for($contract);

        Expect::exception(\Error::class);

        $double->name = 'Ann';
    }

    public function twoInterfacesDeclaringOnePropertyUnionTheirHooks(): void
    {
        if (self::onOldPhp()) {
            return;
        }

        $reader = self::declare('UnionReader', 'namespace %s; interface %s { public string $tag { get; } }');
        $writer = self::declare('UnionWriter', 'namespace %s; interface %s { public string $tag { get; set; } }');

        $double = Understudy::for($reader, $writer);
        $double->tag = 'both';

        Assert::same($double->tag, 'both');
    }

    // --- Forwarding ----------------------------------------------------------

    public function aForwardingDoubleDelegatesReadsAndWritesToTheRealInstance(): void
    {
        if (self::onOldPhp()) {
            return;
        }

        $contract = self::declare('IfaceFwd', 'namespace %s; interface %s { public string $name { get; set; } }');
        $realClass = self::declare(
            'RealFwd',
            'namespace %s; class %s implements \\' . $contract . ' { public string $name = \'real\'; }',
        );

        $real = new $realClass();
        $double = Understudy::delegate($contract, $real);

        Assert::same($double->name, 'real');

        $double->name = 'written';

        Assert::same($real->name, 'written');
        Assert::same($double->name, 'written');
    }

    // --- Refusals that remain -----------------------------------------------

    public function aReadonlyClassTargetWithAnAbstractHookKeepsTheRefusal(): void
    {
        if (self::onOldPhp()) {
            return;
        }

        $contract = self::declare('IfaceForRo', 'namespace %s; interface %s { public string $name { get; } }');
        $target = self::declare(
            'ReadonlyTarget',
            'namespace %s; abstract readonly class %s implements \\' . $contract . ' {}',
        );

        Expect::exception(UnsupportedTarget::class)->withMessage(
            'Cannot create an understudy for `' . $target . '`: the class is readonly and `' . $contract
            . '::$name` is an abstract property hook. A readonly class may only be extended by a readonly '
            . 'class, and a hooked property cannot be readonly — so no double can implement it. '
            . 'Double an interface of the contract instead.',
        );

        Understudy::for($target);
    }

    public function aByReferenceGetHookIsRefused(): void
    {
        if (self::onOldPhp()) {
            return;
        }

        $contract = self::declare('IfaceRefGet', 'namespace %s; interface %s { public string $name { &get; } }');

        Expect::exception(UnsupportedTarget::class)->withMessage(
            'Cannot create an understudy for `' . $contract . '`: `' . $contract . '::$name` declares a '
            . 'by-reference `&get` hook, and a double dispatches property reads by value — the reference it '
            . 'handed back would not be the one the contract promises. Expose the value through a method, '
            . 'or pass a real object.',
        );

        Understudy::for($contract);
    }

    public function twoTargetsDisagreeingOnAPropertyTypeAreRefused(): void
    {
        if (self::onOldPhp()) {
            return;
        }

        $stringy = self::declare('TypeString', 'namespace %s; interface %s { public string $tag { get; } }');
        $inty = self::declare('TypeInt', 'namespace %s; interface %s { public int $tag { get; } }');

        Expect::exception(UnsupportedTarget::class)
            ->withMessageContaining('property `$tag` is declared `int` here and `string` by another target');

        Understudy::for($stringy, $inty);
    }

    // --- Lifetime ------------------------------------------------------------

    public function aPropertyReadIsNotACall(): void
    {
        if (self::onOldPhp()) {
            return;
        }

        $contract = self::declare('IfaceNoCall', 'namespace %s; interface %s { public string $name { get; } }');
        $double = Understudy::for($contract);

        Assert::same($double->name, '');
        Assert::string(Understudy::transcript($double))->contains('received no calls');
    }

    public function aPropertyTouchAfterResetNamesTheProperty(): void
    {
        if (self::onOldPhp()) {
            return;
        }

        $contract = self::declare('IfaceReset', 'namespace %s; interface %s { public string $name { get; } }');
        $double = Understudy::for($contract);

        Understudy::reset();

        Expect::exception(ForgottenDouble::class)
            ->withMessageContaining('its property `$name` was touched');

        $double->name;
    }
}
