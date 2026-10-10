<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\Understudy\Tests\Fixture\Book;
use Rasuvaeff\Understudy\Tests\Fixture\BookRepository;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

use function Rasuvaeff\Understudy\expect;
use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

/**
 * What `verifyAll()` counts as one check — the number runner adapters count
 * real assertions with, rather than a flat one per test.
 */
#[Test]
#[Covers(Understudy::class)]
final class VerifyAllCountTest
{
    #[AfterTest]
    public function tearDown(): void
    {
        Understudy::reset();
    }

    public function returnsOnePerMetExpectation(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('Dune');

        expect(fn() => $repository->save($book));
        expect(fn() => $repository->count());
        expect(fn() => $repository->titles());

        $repository->save($book);
        $repository->count();
        $repository->titles();

        Assert::same(Understudy::verifyAll(), 3);
    }

    public function aStubThatOptedInThroughTimesIsOneCheck(): void
    {
        $repository = Understudy::for(BookRepository::class);

        when(fn() => $repository->count())->returns(1)->times(2);

        $repository->count();
        $repository->count();

        Assert::same(Understudy::verifyAll(), 1);
    }

    public function aPlainStubIsNoCheckWithoutStrictStubs(): void
    {
        $repository = Understudy::for(BookRepository::class);

        when(fn() => $repository->count())->returns(1);

        $repository->count();

        // Permission, not a claim: there was nothing to check, and the count
        // says so — an adapter keeps its own "verification ran" floor.
        Assert::same(Understudy::verifyAll(), 0);
    }

    public function strictStubsMakeEveryStubOneCheck(): void
    {
        $repository = Understudy::for(BookRepository::class);

        when(fn() => $repository->count())->returns(1);
        when(fn() => $repository->titles())->returns([]);

        $repository->count();
        $repository->titles();

        Assert::same(Understudy::verifyAll(strictStubs: true), 2);
    }

    public function anOrderingConstraintIsOneMoreCheck(): void
    {
        $repository = Understudy::for(BookRepository::class);

        expect(fn() => $repository->count())->ordered();
        expect(fn() => $repository->titles())->ordered();

        $repository->count();
        $repository->titles();

        // Two claims, plus the one constraint that they happened in the
        // declared order — a check that could fail on its own.
        Assert::same(Understudy::verifyAll(), 3);
    }

    public function anArmedProtocolIsOneCheck(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('Dune');

        Understudy::expectSequence(
            fn() => $repository->save($book),
            fn() => $repository->count(),
        );

        $repository->save($book);
        $repository->count();

        // The steps were policed at call time; what verifyAll() checks of a
        // protocol is its completeness, and that is one check.
        Assert::same(Understudy::verifyAll(), 1);
    }

    public function checksVerifyMadeEarlierAreNotRecounted(): void
    {
        $repository = Understudy::for(BookRepository::class);

        $repository->count();
        $repository->count();

        verify(fn() => $repository->count(), times: 2);

        // A verify() answered at its own call site; verifyAll() does not
        // re-decide it, and its count stays what this call checked.
        Assert::same(Understudy::verifyAll(), 0);
    }

    public function theCountSpansEveryLiveContext(): void
    {
        $outer = Understudy::for(BookRepository::class);

        expect(fn() => $outer->count());

        $outer->count();

        Understudy::scope(function (): void {
            $inner = Understudy::for(BookRepository::class);

            expect(fn() => $inner->titles());

            $inner->titles();

            // From inside a scope both contexts are live, and the count is
            // the sum over them — one check each. A Fiber body that owns its
            // own context sees the same arithmetic.
            Assert::same(Understudy::verifyAll(), 2);
        });
    }

    public function aSettledPhaseIsNotCountedAgain(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('Dune');

        expect(fn() => $repository->save($book));

        $repository->save($book);

        Understudy::checkpoint();

        // The claim was settled by the checkpoint; the second verification
        // has nothing left to check, and the number follows the work.
        Assert::same(Understudy::verifyAll(), 0);
    }
}
