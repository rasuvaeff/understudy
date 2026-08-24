<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Codegen;

use Rasuvaeff\Understudy\Codegen\TargetUnifier;
use Rasuvaeff\Understudy\Exception\UnsupportedTarget;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\NarrowThenWideReturn;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\NullableObjectParam;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\ParentReturnBase;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\ParentReturnChild;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\SelfConstantExpression;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\TraversableReturn;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\TypedReturn;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\UntypedReturn;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\WiderObjectParam;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

/**
 * Declarations the main unifier suite does not reach: an absent return type,
 * the `parent` keyword, a nullable object rendered as `?X`, a default that is
 * an expression rather than a constant, and two returns where one subsumes
 * the other.
 *
 * Each is a shape Reflection reports differently from everything around it,
 * which is where this file's defects have always lived — the generated class
 * has to satisfy PHP, and PHP does not read Reflection's answer literally.
 *
 * @internal
 */
#[Test]
#[Covers(TargetUnifier::class)]
#[Covers(UnsupportedTarget::class)]
final class TargetUnifierEdgeTest
{
    #[AfterTest]
    public function tearDown(): void
    {
        Understudy::reset();
    }

    public function anAbsentReturnTypeIsReadAsMixed(): void
    {
        // `mixed` is what "no declaration" means, so a typed sibling narrows
        // it rather than conflicting with it.
        $double = Understudy::for(TypedReturn::class, UntypedReturn::class);

        Assert::instanceOf($double, TypedReturn::class);
        Assert::instanceOf($double, UntypedReturn::class);
        Assert::same($double->value(), '');
    }

    public function anUntypedReturnAloneStaysUntyped(): void
    {
        $double = Understudy::for(UntypedReturn::class);

        Assert::null($double->value());
    }

    public function theParentKeywordIsResolvedAgainstTheDeclaringClass(): void
    {
        // Carried through literally, `parent` would mean the generated
        // class's parent — which is this very class, not its base.
        $double = Understudy::for(ParentReturnChild::class);

        Assert::instanceOf($double->make(), ParentReturnBase::class);
    }

    public function aNullableObjectParameterUnifiesWithAWiderUnion(): void
    {
        // `?ParentReturnBase` is rendered with a leading `?`, which the union
        // splitter has to read as two branches rather than as one odd name.
        $double = Understudy::for(NullableObjectParam::class, WiderObjectParam::class);

        $double->accept(null);
        $double->accept(new ParentReturnBase());
        $double->accept('a string');

        Assert::same(count(Understudy::calls(static fn() => $double->accept(null))), 1);
    }

    public function aDefaultComputedFromSelfIsReproducedByValue(): void
    {
        // `self::STEP * 2` is an expression, not a constant name, so
        // Reflection reports no constant — but the value it computes is a
        // plain int, which the generated class can carry without resolving
        // `self` against itself. Refusing here would be over-strict.
        $double = Understudy::for(SelfConstantExpression::class);

        Assert::same($double->step(), 0);

        $parameter = (new \ReflectionMethod($double, 'step'))->getParameters()[0];

        Assert::true($parameter->isDefaultValueAvailable());
        Assert::same($parameter->getDefaultValue(), 6);
    }

    public function aReturnThatAlreadySatisfiesAnotherDoesNotWidenTheIntersection(): void
    {
        // ArrayIterator is a Traversable, so the intersection of the two is
        // just ArrayIterator: keeping both would render a redundant `&`.
        $double = Understudy::for(NarrowThenWideReturn::class, TraversableReturn::class);

        Assert::instanceOf($double->pick(), \ArrayIterator::class);
    }

    public function theSameNarrowingHoldsWhenTheWiderTargetComesFirst(): void
    {
        // The other registration order takes the other branch of the same
        // decision, and has to reach the same answer.
        $double = Understudy::for(TraversableReturn::class, NarrowThenWideReturn::class);

        Assert::instanceOf($double->pick(), \ArrayIterator::class);
    }
}
