<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Codegen;

use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Codegen\TargetUnifier;
use Rasuvaeff\Understudy\Exception\UnsupportedTarget;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\MixedNullDefault;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\NarrowThenWideReturn;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\NullableObjectParam;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\ParentParameterChild;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\ParentReturnBase;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\ParentReturnChild;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\ParentReturnConflict;
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

    /**
     * And in the refusal, which is the place it survived: the message paths
     * rendered the return type without the declaring class, so a reader on
     * PHP 8.3 or 8.4 was told a target declares `: parent` and left to work
     * out which class that is. PHP 8.5 resolves it in Reflection, which is
     * exactly why it went unnoticed — the newest engine papers over it.
     */
    public function theParentKeywordIsResolvedInTheConflictMessageToo(): void
    {
        try {
            Understudy::for(ParentReturnChild::class, ParentReturnConflict::class);
        } catch (UnsupportedTarget $refusal) {
            Assert::string($refusal->getMessage())
                ->contains(ParentReturnBase::class)
                ->notContains(': parent`');

            return;
        }

        Assert::fail('unifying an unsatisfiable pair of return types did not refuse');
    }

    public function theParentKeywordIsResolvedInAParameterToo(): void
    {
        // The dangerous half. A return type written through literally is only
        // narrower than promised; a parameter is illegally narrow, and PHP
        // rejects the generated class rather than the value.
        $double = Understudy::for(ParentParameterChild::class);

        Assert::false($double->accept(new ParentReturnBase()));

        // A union, because the matcher branch is appended to every parameter
        // that can carry one. What matters is which class is in it: the
        // resolved parent, never the keyword.
        $rendered = (string) (new \ReflectionMethod($double, 'accept'))->getParameters()[0]->getType();

        Assert::string($rendered)->contains(ParentReturnBase::class);
        Assert::false(str_contains($rendered, 'parent'));
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

    /**
     * `mixed $v = null` used to render as `mixed|null`, which PHP refuses at
     * compile time — a fatal out of `eval()`, uncatchable, killing the whole
     * run for a signature that is neither exotic nor rare. The widening
     * belongs to the branch where the union is real, and the default belongs
     * to dispatch.
     */
    public function aMixedParameterWithANullDefaultIsDoublable(): void
    {
        $double = Understudy::for(MixedNullDefault::class);

        $double->accept();
        $double->accept('given');

        Assert::same(
            array_map(
                static fn(\Rasuvaeff\Understudy\Invocation $call): mixed => $call->args[0],
                Understudy::calls(static function () use ($double): void {
                    $double->accept(Arg::any());
                }),
            ),
            [null, 'given'],
        );
    }

    public function aDefaultComputedFromSelfIsReproducedByValue(): void
    {
        // `self::STEP * 2` is an expression, not a constant name, so
        // Reflection reports no constant — but the value it computes is a
        // plain int, which the generated class can carry without resolving
        // `self` against itself. Refusing here would be over-strict.
        $double = Understudy::for(SelfConstantExpression::class);

        Assert::same($double->step(), 0);

        // The generated parameter carries the sentinel — what the contract
        // computed is put back by dispatch, and the call log is where that is
        // visible.
        $double->step();

        Assert::same(Understudy::lastCall(static fn(): int => $double->step(Arg::any()))?->args, [6]);
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
