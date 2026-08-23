<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Codegen;

use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Codegen\TargetUnifier;
use Rasuvaeff\Understudy\Tests\Fixture\Defaults\EnumInArray;
use Rasuvaeff\Understudy\Tests\Fixture\Defaults\GlobalConstant;
use Rasuvaeff\Understudy\Tests\Fixture\Defaults\HiddenConstant;
use Rasuvaeff\Understudy\Tests\Fixture\Defaults\ObjectInArray;
use Rasuvaeff\Understudy\Tests\Fixture\Defaults\ParentConstant;
use Rasuvaeff\Understudy\Tests\Fixture\Defaults\StringMentioningSelf;
use Rasuvaeff\Understudy\Tests\Fixture\Suit;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

/**
 * Every shape a parameter default can take, compared by calling the method
 * without the argument and reading what the call recorded.
 *
 * Comparing the *value* rather than the generated source is the point: what the
 * contract promises is what the caller would have received, and a rendering that
 * produces something else is wrong however plausible it reads.
 */
#[Test]
#[Covers(TargetUnifier::class)]
final class DefaultRenderingTest
{
    #[AfterTest]
    public function tearDown(): void
    {
        Understudy::reset();
    }

    /**
     * `parent::INHERITED` is public, so it can be named — through the class that
     * declares it, never as `parent`, which in the double means something else.
     */
    public function aParentConstantIsRenderedThroughItsDeclaringClass(): void
    {
        $double = Understudy::for(ParentConstant::class);

        $double->greet();

        Assert::same($this->firstArgument(static fn(): string => $double->greet(Arg::any())), 'from-parent');
    }

    /**
     * The same shape, protected. It cannot be named from outside the hierarchy,
     * so the value is what the double answers with.
     */
    public function anUnreachableParentConstantFallsBackToItsValue(): void
    {
        $double = Understudy::for(HiddenConstant::class);

        $double->step();

        Assert::same($this->firstArgument(static fn(): int => $double->step(Arg::any())), 7);
    }

    /**
     * Reflection reports a global constant prefixed with the declaring
     * namespace — a name that does not exist. Only the value can be rendered.
     */
    public function aGlobalConstantIsRenderedAsItsValue(): void
    {
        $double = Understudy::for(GlobalConstant::class);

        $double->sized();

        Assert::same($this->firstArgument(static fn(): int => $double->sized(Arg::any())), PHP_INT_MAX);
    }

    public function anEnumCaseInsideAnArrayIsKept(): void
    {
        $double = Understudy::for(EnumInArray::class);

        $double->deal();

        Assert::same(
            $this->firstArgument(static fn(): int => $double->deal(Arg::any())),
            ['first' => Suit::Hearts],
        );
    }

    /**
     * The words `self::` and `new` inside a string are not code. Reading the
     * text rather than the tokens would refuse a contract that is ordinary.
     */
    public function aStringMentioningSelfOrNewIsJustAString(): void
    {
        $double = Understudy::for(StringMentioningSelf::class);

        $double->describe();

        Assert::same(
            $this->firstArgument(static fn(): string => $double->describe(Arg::any())),
            'see self::PREFIX or new Foo()',
        );
    }

    public function anArrayConstantIsRenderedAsItsValue(): void
    {
        $double = Understudy::for(ObjectInArray::class);

        $double->hold();

        Assert::same(
            $this->firstArgument(static fn(): string => $double->hold(Arg::any())),
            ['tag' => 'plain'],
        );
    }

    private function firstArgument(callable $call): mixed
    {
        $calls = Understudy::calls($call);

        Assert::same(count($calls), 1);

        return $calls[0]->args[0];
    }
}
