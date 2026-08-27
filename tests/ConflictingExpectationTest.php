<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Exception\ConflictingExpectation;
use Rasuvaeff\Understudy\Expectation\Expectation;
use Rasuvaeff\Understudy\Runtime\DoubleState;
use Rasuvaeff\Understudy\Tests\Fixture\Book;
use Rasuvaeff\Understudy\Tests\Fixture\BookRepository;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

use function Rasuvaeff\Understudy\expect;
use function Rasuvaeff\Understudy\when;

/**
 * A stub and an expectation for the exact same call have no working layering:
 * whichever is declared later takes the dispatch, and the earlier one silently
 * loses its purpose. Both orders are refused at registration; every layering
 * with a working reading — two stubs, a broad fallback under a narrow claim —
 * stays exactly as documented.
 */
#[Test]
#[Covers(ConflictingExpectation::class)]
#[Covers(DoubleState::class)]
#[Covers(Expectation::class)]
final class ConflictingExpectationTest
{
    #[AfterTest]
    public function tearDown(): void
    {
        Understudy::reset();
    }

    // --- The refusals --------------------------------------------------------

    public function aClaimAfterAStubForTheSameCallIsRefused(): void
    {
        $repository = Understudy::for(BookRepository::class);
        when(fn() => $repository->find(7))->returns(new Book('Dune'));

        Expect::exception(ConflictingExpectation::class)->withMessageContaining(
            'already has `find(7)` stubbed',
        );

        expect(fn() => $repository->find(7));
    }

    public function theRefusalNamesTheOneExpectationIdioms(): void
    {
        $repository = Understudy::for(BookRepository::class);
        when(fn() => $repository->find(7))->returns(new Book('Dune'));

        // The message has to hand the reader the way out, not just the verdict.
        Expect::exception(ConflictingExpectation::class)->withMessageContaining(
            'expect(...)->returns(...), or count the stub with when(...)->times(...)',
        );

        expect(fn() => $repository->find(7));
    }

    /**
     * The deferred-action shape of the same mistake: the stub has no action
     * *yet* when the expectation arrives, and the builder would hand it one a
     * line later. Waiting to see whether that happens would let the silent
     * order-dependence back in, so the stub's mere registration is enough.
     */
    public function aClaimAfterAnActionlessStubIsRefusedToo(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $builder = when(fn() => $repository->find(7));

        try {
            expect(fn() => $repository->find(7));

            Assert::fail('Expected the colliding expect() to be refused');
        } catch (ConflictingExpectation $refusal) {
            Assert::string($refusal->getMessage())->contains('already has `find(7)` stubbed');
        }

        // The builder still belongs to the surviving stub.
        $builder->returns(new Book('Dune'));

        Assert::same($repository->find(7)?->title, 'Dune');
    }

    public function aStubAfterACountedExpectationIsRefused(): void
    {
        $repository = Understudy::for(BookRepository::class);
        expect(fn() => $repository->find(7));

        Expect::exception(ConflictingExpectation::class)->withMessageContaining(
            'already counts `find(7)`',
        );

        when(fn() => $repository->find(7));
    }

    public function aStubAfterACountedStubIsRefusedTheSameWay(): void
    {
        $repository = Understudy::for(BookRepository::class);
        when(fn() => $repository->count())->returns(1)->times(2);

        // times() turned the stub into a claim about counts, so a later stub
        // for the same call would starve it exactly like an expect().
        Expect::exception(ConflictingExpectation::class)->withMessageContaining(
            'Give the expectation its behaviour instead',
        );

        when(fn() => $repository->count());
    }

    public function aSecondCountedExpectationForTheSameCallIsRefused(): void
    {
        $repository = Understudy::for(BookRepository::class);
        expect(fn() => $repository->find(7));

        Expect::exception(ConflictingExpectation::class)->withMessageContaining(
            'Declare the count once — expect(...)->times(...)',
        );

        expect(fn() => $repository->find(7));
    }

    public function equalMatcherSpecificationsCollide(): void
    {
        $repository = Understudy::for(BookRepository::class);
        when(fn() => $repository->find(Arg::int(min: 1, max: 5)))->returns(new Book('Dune'));

        // Two matchers built on different lines, parameterised identically,
        // are the same specification — identity would miss every real
        // collision, because nobody reuses the matcher instance.
        Expect::exception(ConflictingExpectation::class)->withMessageContaining(
            'already has `find(', // the matcher renders inside the spec
        );

        expect(fn() => $repository->find(Arg::int(min: 1, max: 5)));
    }

    public function aRefusedRegistrationLeavesNoTrace(): void
    {
        $repository = Understudy::for(BookRepository::class);
        when(fn() => $repository->find(7))->returns($book = new Book('Dune'));

        try {
            expect(fn() => $repository->find(7));
        } catch (ConflictingExpectation) {
            // The half-registered claim must not survive the refusal.
        }

        Assert::same($repository->find(7), $book, 'the stub still answers');

        // And nothing was left for verifyAll() to report.
        Understudy::verifyAll();
    }

    // --- The layerings that keep working -------------------------------------

    public function twoPlainStubsForTheSameCallStillLayer(): void
    {
        $repository = Understudy::for(BookRepository::class);
        when(fn() => $repository->find(7))->returns(new Book('first'));
        when(fn() => $repository->find(7))->returns($second = new Book('second'));

        Assert::same($repository->find(7), $second, 'most recently registered wins');
    }

    public function aBroadStubAndANarrowClaimStillCompose(): void
    {
        $repository = Understudy::for(BookRepository::class);
        when(fn() => $repository->find(Arg::any()))->returns($fallback = new Book('fallback'));
        expect(fn() => $repository->find(7));

        // Overlap is not equality: the claim answers its own call with the
        // mode default, the fallback answers everything else — the layering
        // the README documents, untouched.
        Assert::null($repository->find(7));
        Assert::same($repository->find(3), $fallback);

        Understudy::verifyAll();
    }

    public function differentMatcherParametersAreDifferentSpecifications(): void
    {
        $repository = Understudy::for(BookRepository::class);
        when(fn() => $repository->find(Arg::int(min: 1, max: 5)))->returns(new Book('narrow'));
        expect(fn() => $repository->find(Arg::int(min: 1, max: 9)));

        Assert::null($repository->find(7));

        Understudy::verifyAll();
    }

    public function aLiteralAndAMatcherAreDifferentSpecifications(): void
    {
        $repository = Understudy::for(BookRepository::class);
        when(fn() => $repository->find(7))->returns(new Book('Dune'));
        expect(fn() => $repository->find(Arg::same(7)));

        Assert::null($repository->find(7), 'the newer claim answers the default');

        Understudy::verifyAll();
    }

    public function aDifferentArityIsADifferentSpecification(): void
    {
        $repository = Understudy::for(BookRepository::class);
        when(fn() => $repository->tag('a'))->returns('stubbed');
        expect(fn() => $repository->tag('a', 2));

        // tag('a') materialises the default weight 1, so the two really are
        // different calls, not two spellings of one.
        Assert::same($repository->tag('a'), 'stubbed');
        Assert::same($repository->tag('a', 2), '');

        Understudy::verifyAll();
    }

    public function differentMethodsNeverCollide(): void
    {
        $repository = Understudy::for(BookRepository::class);
        when(fn() => $repository->count())->returns(3);
        expect(fn() => $repository->titles())->returns(['Dune']);

        Assert::same($repository->count(), 3);
        Assert::same($repository->titles(), ['Dune']);

        Understudy::verifyAll();
    }

    public function theSameSpecificationOnAnotherDoubleIsIndependent(): void
    {
        $first = Understudy::for(BookRepository::class);
        $second = Understudy::for(BookRepository::class);

        when(fn() => $first->find(7))->returns($book = new Book('Dune'));
        expect(fn() => $second->find(7));

        Assert::same($first->find(7), $book);
        Assert::null($second->find(7));

        Understudy::verifyAll();
    }

    public function aSettledCheckpointFreesTheSpecification(): void
    {
        $repository = Understudy::for(BookRepository::class);
        expect(fn() => $repository->find(7));
        $repository->find(7);

        Understudy::checkpoint();

        // The claim is settled and gone; the next phase may stub the call.
        when(fn() => $repository->find(7))->returns($book = new Book('Dune'));

        Assert::same($repository->find(7), $book);
    }

    // --- The equality itself, directly ---------------------------------------

    public function specEqualityIsScopedToTheMethodItself(): void
    {
        $stub = new Expectation('find', [7]);

        // Same arity, different method: the registration buckets already
        // separate methods, but the equality must not lean on that.
        Assert::false($stub->specEquals(new Expectation('describe', [7])));
        Assert::true($stub->specEquals(new Expectation('find', [7])));
    }

    public function everyPositionOfTheSpecificationCounts(): void
    {
        $matcherThenOne = new Expectation('tag', [Arg::any(), 1]);

        // An equal matcher in front must not end the comparison early.
        Assert::false($matcherThenOne->specEquals(new Expectation('tag', [Arg::any(), 2])));
        Assert::true($matcherThenOne->specEquals(new Expectation('tag', [Arg::any(), 1])));
    }

    public function expectWithBehaviourRemainsTheOneExpectationIdiom(): void
    {
        $repository = Understudy::for(BookRepository::class);
        expect(fn() => $repository->find(7))->returns($book = new Book('Dune'));

        Assert::same($repository->find(7), $book);

        Understudy::verifyAll();
    }
}
