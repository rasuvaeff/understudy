<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Expectation\Expectation;
use Rasuvaeff\Understudy\Runtime\DoubleState;
use Rasuvaeff\Understudy\Tests\Fixture\Book;
use Rasuvaeff\Understudy\Tests\Fixture\BookRepository;
use Rasuvaeff\Understudy\Tests\Fixture\VariadicSink;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

use function Rasuvaeff\Understudy\expect;
use function Rasuvaeff\Understudy\when;

/**
 * Dispatch tiers: an expectation that pins at least one argument is tried
 * before one that says nothing about any argument, whichever order the two
 * were declared in — a catch-all cannot steal the calls a specific stub
 * describes.
 */
#[Test]
#[Covers(DoubleState::class)]
#[Covers(Expectation::class)]
final class DispatchTierTest
{
    #[AfterTest]
    public function tearDown(): void
    {
        Understudy::reset();
    }

    public function aCatchAllRegisteredAfterASpecificStubStealsNothing(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('Dune');

        when(fn() => $repository->find(1))->returns($book);
        when(fn() => $repository->find(Arg::any()))->returns(null);

        // The trap #136 asked about, now unwriteable: whichever order the two
        // were declared in, the specific stub answers its own call.
        Assert::same($repository->find(1), $book);
        Assert::null($repository->find(2));
    }

    public function aSpecificMatcherBeatsALaterCatchAll(): void
    {
        $repository = Understudy::for(BookRepository::class);

        // The crossing does not need a literal: a narrowing matcher in the
        // walked list is the same tier rule as a literal in the index.
        when(fn() => $repository->find(Arg::int(min: 1, max: 5)))->returns(new Book('narrow'));
        when(fn() => $repository->find(Arg::any()))->returns(new Book('wide'));

        Assert::same($repository->find(3)?->title, 'narrow');
        Assert::same($repository->find(9)?->title, 'wide');
    }

    public function anUnspelledOptionalParameterIsTheGenericTier(): void
    {
        $repository = Understudy::for(BookRepository::class);

        // tag(Arg::any()) leaves the optional weight unspelled: no position
        // pins anything, so the specification dispatches as a catch-all. The
        // literal in the other one pins — and answers its calls even though
        // the catch-all was declared after it.
        when(fn() => $repository->tag(Arg::any()))->returns('wide');
        when(fn() => $repository->tag('alpha'))->returns('pinned');

        Assert::same($repository->tag('alpha', 3), 'pinned');
        Assert::same($repository->tag('beta'), 'wide');
    }

    public function aTailLeftUnpinnedIsTheGenericTier(): void
    {
        $sink = Understudy::for(VariadicSink::class);

        // In the first specification every position says something: any()
        // says nothing about the channel, but none() pins the tail empty.
        // In the second, rest() stands for "the rest does not matter" — a
        // catch-all, so the pinned tail answers the empty write even though
        // the catch-all was declared after it.
        when(fn() => $sink->write(Arg::any(), Arg::none()))->returns(true);
        when(fn() => $sink->write(Arg::rest()))->returns(false);

        Assert::true($sink->write('log'));
        Assert::false($sink->write('log', 1));
    }

    public function twoSpecificStubsKeepTheDeclaredLayering(): void
    {
        $repository = Understudy::for(BookRepository::class);

        when(fn() => $repository->find(Arg::int(min: 1, max: 5)))->returns(new Book('range'));
        when(fn() => $repository->find(Arg::int(min: 3, max: 9)))->returns(new Book('later'));

        // Both pin the argument: registration order decides between them,
        // and matchers are never compared for narrowness.
        Assert::same($repository->find(4)?->title, 'later');
        Assert::same($repository->find(1)?->title, 'range');
    }

    public function twoCatchAllsKeepTheDeclaredLayering(): void
    {
        $repository = Understudy::for(BookRepository::class);

        when(fn() => $repository->find(Arg::any()))->returns(new Book('first'));
        when(fn() => $repository->find(Arg::any()))->returns(new Book('second'));

        Assert::same($repository->find(7)?->title, 'second');
    }

    public function theByReferenceSlotWalkAppliesTheSameTiers(): void
    {
        $registry = Understudy::for(\Rasuvaeff\Understudy\Tests\Fixture\Ref\Registry::class);

        // A generic stub with an action, declared after an action-less
        // specific claim: dispatch hands the call to the claim, which has
        // nothing to answer with, so the mode's own value comes back — and
        // the slot decision has to agree, or the reference would be seeded
        // with a value dispatch never returned.
        when(fn() => $registry->row(Arg::any()))->returns(['generic' => true]);
        expect(fn() => $registry->row(1));

        $row = &$registry->row(1);
        $row['written'] = true;

        Assert::same($row, ['written' => true]);
    }
}
