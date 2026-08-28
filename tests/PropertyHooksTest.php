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
    private function declare(string $suffix, string $declaration): string
    {
        $name = 'Hook' . $suffix . '_' . PHP_VERSION_ID;
        /** @var class-string $fqcn */
        $fqcn = __NAMESPACE__ . '\\' . $name;

        if (!interface_exists($fqcn, autoload: false) && !class_exists($fqcn, autoload: false)) {
            eval(sprintf($declaration, __NAMESPACE__, $name));
        }

        return $fqcn;
    }

    private function onOldPhp(): bool
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
        if ($this->onOldPhp()) {
            return;
        }

        $contract = $this->declare('IfaceGet', 'namespace %s; interface %s { public string $name { get; } }');
        $double = Understudy::for($contract);

        Assert::instanceOf($double, $contract);
        Assert::same($double->name, '');
    }

    public function theRenderedPropertyIsVirtualAndSatisfiesTheContract(): void
    {
        if ($this->onOldPhp()) {
            return;
        }

        $contract = $this->declare('IfaceVirtual', 'namespace %s; interface %s { public int $count { get; } }');
        $double = Understudy::for($contract);
        $property = new \ReflectionProperty($double, 'count');

        Assert::true($property->isVirtual());
        Assert::false($property->isAbstract());
    }

    public function aGetSetPropertyRoundTripsWhatTheCodeUnderTestWrote(): void
    {
        if ($this->onOldPhp()) {
            return;
        }

        $contract = $this->declare('IfaceGetSet', 'namespace %s; interface %s { public string $name { get; set; } }');
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
        if ($this->onOldPhp()) {
            return;
        }

        $clock = Clock::class;
        $contract = $this->declare('IfaceNullable', 'namespace %s; interface %s { public ?\\' . $clock . ' $clock { get; set; } }');

        $double = Understudy::for($contract);
        Understudy::defaults(Clock::class, static fn(): object => Understudy::for(Clock::class));

        Assert::instanceOf($double->clock, Clock::class);

        $double->clock = null;

        Assert::null($double->clock);
    }

    public function anObjectTypedPropertyAnswersADepthOneDouble(): void
    {
        if ($this->onOldPhp()) {
            return;
        }

        $contract = $this->declare('IfaceObject', 'namespace %s; interface %s { public \\' . Clock::class . ' $clock { get; } }');

        $double = Understudy::for($contract);

        Assert::instanceOf($double->clock, Clock::class);
    }

    public function aDefaultsRegistrationOutranksTheNestedDouble(): void
    {
        if ($this->onOldPhp()) {
            return;
        }

        $contract = $this->declare('IfaceRegistered', 'namespace %s; interface %s { public \\' . Clock::class . ' $clock { get; } }');

        $pinned = Understudy::for(Clock::class);
        Understudy::defaults(Clock::class, static fn(): object => $pinned);

        Assert::same(Understudy::for($contract)->clock, $pinned);
    }

    public function anAbstractClassHookIsRenderedTheSameWay(): void
    {
        if ($this->onOldPhp()) {
            return;
        }

        $contract = $this->declare('AbstractGet', 'namespace %s; abstract class %s { abstract public string $tag { get; } }');

        Assert::same(Understudy::for($contract)->tag, '');
    }

    /**
     * Only the ABSTRACT hooks are collected: a concrete plain property on the
     * same class stays a real property — filled by its own default, never
     * dispatched — and the abstract hook after it still renders.
     */
    public function aConcretePropertyBesideAnAbstractHookStaysReal(): void
    {
        if ($this->onOldPhp()) {
            return;
        }

        $contract = $this->declare(
            'MixedProps',
            'namespace %s; abstract class %s { public int $plain = 3; abstract public string $tag { get; } }',
        );

        $double = Understudy::for($contract);

        Assert::same($double->tag, '');
        Assert::same($double->plain, 3);
        Assert::false((new \ReflectionProperty($double, 'plain'))->isVirtual());
    }

    /**
     * Every abstract hooked property renders, not just the first one the walk
     * met — and each dispatches independently.
     */
    public function twoHookedPropertiesBothRenderAndDispatch(): void
    {
        if ($this->onOldPhp()) {
            return;
        }

        $contract = $this->declare(
            'TwoProps',
            'namespace %s; interface %s { public string $name { get; set; } public int $age { get; set; } }',
        );

        $double = Understudy::for($contract);

        Assert::same($double->name, '');
        Assert::same($double->age, 0);

        $double->name = 'Ann';
        $double->age = 7;

        Assert::same($double->name, 'Ann');
        Assert::same($double->age, 7);
    }

    /**
     * A property touched from another Fiber is answered by the context that
     * OWNS the double, the same routing a cross-Fiber call gets: the value a
     * Fiber wrote is what the owning test reads back.
     */
    public function aPropertyTouchFromAnotherFiberReachesTheOwnersState(): void
    {
        if ($this->onOldPhp()) {
            return;
        }

        $contract = $this->declare('FiberProp', 'namespace %s; interface %s { public string $name { get; set; } }');
        $double = Understudy::for($contract);

        $read = null;
        $fiber = new \Fiber(static function () use ($double, &$read): void {
            $double->name = 'from the fiber';
            $read = $double->name;
        });
        $fiber->start();

        Assert::same($read, 'from the fiber');
        Assert::same($double->name, 'from the fiber');
    }

    /**
     * A forwarding write goes THROUGH to the real instance and only there:
     * the double's own store stays untouched, so leaving forwarding mode
     * afterwards reads the mode default, not a shadow copy of the write.
     */
    public function aForwardingWriteLeavesTheDoublesOwnStoreEmpty(): void
    {
        if ($this->onOldPhp()) {
            return;
        }

        $contract = $this->declare('FwdStore', 'namespace %s; interface %s { public string $name { get; set; } }');
        $realClass = $this->declare(
            'FwdStoreReal',
            'namespace %s; class %s implements \\' . $contract . ' { public string $name = \'real\'; }',
        );

        $real = new $realClass();
        $double = Understudy::delegate($contract, $real);

        $double->name = 'written';

        Assert::same($real->name, 'written');

        Understudy::strict($double);

        Assert::same($double->name, '');
    }

    /**
     * Exactly the declared hooks are rendered: a get-only contract gets no
     * `set`, and PHP itself refuses the write to the virtual property — a
     * write the contract never promised must not succeed silently.
     */
    public function aWriteToAGetOnlyPropertyIsRefusedByPhpItself(): void
    {
        if ($this->onOldPhp()) {
            return;
        }

        $contract = $this->declare('IfaceReadOnly', 'namespace %s; interface %s { public string $name { get; } }');
        $double = Understudy::for($contract);

        Expect::exception(\Error::class);

        $double->name = 'Ann';
    }

    public function twoInterfacesDeclaringOnePropertyUnionTheirHooks(): void
    {
        if ($this->onOldPhp()) {
            return;
        }

        $reader = $this->declare('UnionReader', 'namespace %s; interface %s { public string $tag { get; } }');
        $writer = $this->declare('UnionWriter', 'namespace %s; interface %s { public string $tag { get; set; } }');

        $double = Understudy::for($reader, $writer);
        $double->tag = 'both';

        Assert::same($double->tag, 'both');
    }

    /**
     * The union folds each hook independently: a get-only and a set-only
     * declaration of one property meet in a double that can do both.
     */
    public function aGetOnlyAndASetOnlyDeclarationUnionIntoBoth(): void
    {
        if ($this->onOldPhp()) {
            return;
        }

        $reader = $this->declare('UnionGetHalf', 'namespace %s; interface %s { public string $tag { get; } }');
        $writer = $this->declare('UnionSetHalf', 'namespace %s; interface %s { public string $tag { set; } }');

        $double = Understudy::for($reader, $writer);
        $double->tag = 'both halves';

        Assert::same($double->tag, 'both halves');
    }

    /**
     * And when both targets declare both hooks, both survive — a fold that
     * needed agreement instead of presence would quietly drop one.
     */
    public function twoFullDeclarationsKeepBothHooks(): void
    {
        if ($this->onOldPhp()) {
            return;
        }

        $one = $this->declare('UnionFullOne', 'namespace %s; interface %s { public string $tag { get; set; } }');
        $two = $this->declare('UnionFullTwo', 'namespace %s; interface %s { public string $tag { get; set; } }');

        $double = Understudy::for($one, $two);
        $double->tag = 'kept';

        Assert::same($double->tag, 'kept');
    }

    // --- Forwarding ----------------------------------------------------------

    public function aForwardingDoubleDelegatesReadsAndWritesToTheRealInstance(): void
    {
        if ($this->onOldPhp()) {
            return;
        }

        $contract = $this->declare('IfaceFwd', 'namespace %s; interface %s { public string $name { get; set; } }');
        $realClass = $this->declare('RealFwd', 'namespace %s; class %s implements \\' . $contract . ' { public string $name = \'real\'; }');

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
        if ($this->onOldPhp()) {
            return;
        }

        $contract = $this->declare('IfaceForRo', 'namespace %s; interface %s { public string $name { get; } }');
        $target = $this->declare('ReadonlyTarget', 'namespace %s; abstract readonly class %s implements \\' . $contract . ' {}');

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
        if ($this->onOldPhp()) {
            return;
        }

        $contract = $this->declare('IfaceRefGet', 'namespace %s; interface %s { public string $name { &get; } }');

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
        if ($this->onOldPhp()) {
            return;
        }

        $stringy = $this->declare('TypeString', 'namespace %s; interface %s { public string $tag { get; } }');
        $inty = $this->declare('TypeInt', 'namespace %s; interface %s { public int $tag { get; } }');

        Expect::exception(UnsupportedTarget::class)->withMessage(
            'Cannot create an understudy for `' . $inty . '`: property `$tag` is declared `int` here and '
            . '`string` by another target. Property types are invariant, so no single declaration '
            . 'satisfies both.',
        );

        Understudy::for($stringy, $inty);
    }

    // --- Lifetime ------------------------------------------------------------

    public function aPropertyReadIsNotACall(): void
    {
        if ($this->onOldPhp()) {
            return;
        }

        $contract = $this->declare('IfaceNoCall', 'namespace %s; interface %s { public string $name { get; } }');
        $double = Understudy::for($contract);

        Assert::same($double->name, '');
        Assert::string(Understudy::transcript($double))->contains('received no calls');
    }

    public function aPropertyTouchAfterResetNamesTheProperty(): void
    {
        if ($this->onOldPhp()) {
            return;
        }

        $contract = $this->declare('IfaceReset', 'namespace %s; interface %s { public string $name { get; } }');
        $double = Understudy::for($contract);

        Understudy::reset();

        Expect::exception(ForgottenDouble::class)->withMessage(
            "This understudy is no longer known to Understudy, but its property `\$name` was touched.\n"
            . 'It was created before a reset(); create doubles inside the test that uses them '
            . 'rather than sharing one across tests.',
        );

        $double->name;
    }
}
