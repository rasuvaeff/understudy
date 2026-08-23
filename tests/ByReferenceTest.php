<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Runtime\DoubleState;
use Rasuvaeff\Understudy\Runtime\Runtime;
use Rasuvaeff\Understudy\Tests\Fixture\Ref\RealRegistry;
use Rasuvaeff\Understudy\Tests\Fixture\Ref\Registry;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(Runtime::class)]
#[Covers(DoubleState::class)]
final class ByReferenceTest
{
    #[AfterTest]
    public function tearDown(): void
    {
        Understudy::reset();
    }

    // --- Returning by reference ----------------------------------------------

    /**
     * `&values()` promises a reference into something that stays put. A
     * reference to a local would be replaced by the next call, and nothing
     * written through it would survive.
     */
    public function aMutationThroughTheReturnedReferenceSurvives(): void
    {
        $registry = Understudy::for(Registry::class);

        $values = &$registry->values();
        $values['a'] = 1;

        Assert::same($registry->values(), ['a' => 1]);
    }

    /**
     * The slot is seeded with what the mode answered, not left empty: a first
     * read has to see `[]` for an `array` return, the same value any other
     * method would give.
     */
    public function theSlotIsSeededWithTheModesOwnAnswer(): void
    {
        $registry = Understudy::for(Registry::class);

        Assert::same($registry->values(), []);
        Assert::same($registry->names(), []);
    }

    /**
     * Seeded once and then kept. A loose default recomputes an empty value on
     * every call, and writing that back would undo what the caller wrote
     * through the reference it was handed.
     */
    public function aLooseDefaultDoesNotOverwriteWhatWasWritten(): void
    {
        $registry = Understudy::for(Registry::class);

        $values = &$registry->values();
        $values['a'] = 1;

        $registry->values();
        $registry->values();

        Assert::same($registry->values(), ['a' => 1]);
    }

    /**
     * An expectation that only counts the call is not an answer, so it must not
     * replace the slot either.
     */
    public function anExpectationWithoutAnActionLeavesTheSlotAlone(): void
    {
        $registry = Understudy::for(Registry::class);

        $values = &$registry->values();
        $values['a'] = 1;

        \Rasuvaeff\Understudy\expect(static fn(): array => $registry->values())->times(1);

        Assert::same($registry->values(), ['a' => 1]);

        Understudy::verifyAll();
    }

    public function twoReferencesToOneMethodAliasTheSameSlot(): void
    {
        $registry = Understudy::for(Registry::class);

        $first = &$registry->values();
        $first['a'] = 1;
        $second = &$registry->values();
        $second['b'] = 2;

        Assert::same($first, ['a' => 1, 'b' => 2]);
    }

    /**
     * Two by-reference methods on one double are two slots. Sharing one would
     * make writing to `values()` show up in `names()`.
     */
    public function eachMethodHasItsOwnSlot(): void
    {
        $registry = Understudy::for(Registry::class);

        $values = &$registry->values();
        $values['a'] = 1;

        Assert::same($registry->names(), []);
    }

    public function slotsAreNotSharedBetweenDoubles(): void
    {
        $first = Understudy::for(Registry::class);
        $second = Understudy::for(Registry::class);

        $values = &$first->values();
        $values['a'] = 1;

        Assert::same($second->values(), []);
    }

    /**
     * A test that says what the method returns means it, so a configured answer
     * replaces the slot — where a loose default, which recomputes an empty
     * value every time, must not.
     */
    public function aConfiguredAnswerReplacesTheSlot(): void
    {
        $registry = Understudy::for(Registry::class);

        $values = &$registry->values();
        $values['written'] = true;

        when(static fn(): array => $registry->values())->returns(['configured' => true]);

        Assert::same($registry->values(), ['configured' => true]);
    }

    public function aByReferenceCallIsRecordedLikeAnyOther(): void
    {
        $registry = Understudy::for(Registry::class);

        $registry->values();
        $registry->values();

        Assert::same(count(Understudy::calls(static fn(): array => $registry->values())), 2);
    }

    // --- Passing by reference -------------------------------------------------

    /**
     * The log holds two readings of the arguments, because a by-reference one
     * is the caller's variable and whatever answered the call may have written
     * to it. One reading would show a value the caller never passed.
     */
    public function theLogKeepsBothSidesOfAByReferenceArgument(): void
    {
        $registry = Understudy::for(Registry::class);
        Understudy::forwarding($registry, new RealRegistry());

        $slot = 'before';
        $registry->fill($slot, 'after');

        $call = Understudy::calls(static fn() => $registry->fill(Arg::any(), Arg::any()))[0];

        Assert::same($slot, 'after');
        Assert::same($call->args[0], 'before');
        Assert::same($call->argsAfter()[0], 'after');
    }

    /**
     * A method that cannot move its arguments pays nothing for the two
     * readings, so there is only one.
     */
    public function aMethodWithoutReferencesKeepsOneReading(): void
    {
        $registry = Understudy::for(Registry::class);

        $registry->count();

        Assert::null(Understudy::calls(static fn(): int => $registry->count())[0]->argsAfter());
    }

    /**
     * The second reading is taken whichever way the call ended: an answer that
     * wrote through the reference and then threw still wrote. Asserting the
     * value rather than its presence is what keeps a pre-call snapshot from
     * passing for a post-call one.
     */
    public function aThrownCallStillRecordsWhatWasWritten(): void
    {
        $registry = Understudy::for(Registry::class);
        Understudy::forwarding($registry, new RealRegistry());

        when(static fn() => $registry->fill(Arg::any(), Arg::any()))
            ->answers(static function (\Rasuvaeff\Understudy\Invocation $call): never {
                $call->callOriginal();

                throw new \DomainException('after writing');
            });

        $slot = 'before';

        try {
            $registry->fill($slot, 'written');
        } catch (\DomainException) {
            // The outcome is not what this asserts.
        }

        $call = Understudy::calls(static fn() => $registry->fill(Arg::any(), Arg::any()))[0];

        Assert::same($call->args[0], 'before');
        Assert::same($call->argsAfter()[0], 'written');
        Assert::same($slot, 'written');
    }

    /**
     * A reference can sit at any depth inside an array argument. Detaching only
     * the top level would leave a nested row shared, and the "before" reading
     * would change under the answer that wrote to it.
     */
    public function theFirstReadingSurvivesAWriteNestedInsideAnArrayArgument(): void
    {
        $registry = Understudy::for(Registry::class);
        Understudy::forwarding($registry, new RealRegistry());

        $rows = ['nested' => ['deep' => 'before']];
        $registry->absorb($rows);

        $call = Understudy::calls(static fn() => $registry->absorb(Arg::any()))[0];

        Assert::same($rows['nested']['deep'], 'written');
        Assert::same($call->args[0]['nested']['deep'], 'before');
        Assert::same($call->argsAfter()[0]['nested']['deep'], 'written');
    }

    /**
     * The expectation that would answer the call decides whether the slot is
     * replaced, and "would answer" means the dispatcher's own precedence: the
     * newest match wins, and a match without an action is not an answer.
     */
    public function aNewerCountingExpectationDoesNotCountAsAConfiguredAnswer(): void
    {
        $registry = Understudy::for(Registry::class);

        when(static fn(): array => $registry->values())->returns(['stubbed' => true]);

        $values = &$registry->values();
        $values['written'] = true;

        \Rasuvaeff\Understudy\expect(static fn(): array => $registry->values())->times(1);

        Assert::same($registry->values(), ['stubbed' => true, 'written' => true]);

        Understudy::verifyAll();
    }
}
