<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Exception\VerificationFailed;
use Rasuvaeff\Understudy\FailureKind;
use Rasuvaeff\Understudy\Tests\Fixture\Book;
use Rasuvaeff\Understudy\Tests\Fixture\BookRepository;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Understudy\VerificationFailure;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

use function Rasuvaeff\Understudy\expect;
use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

/**
 * `failures()`: the structured half of a VerificationFailed report. Every
 * kind states its facts in addressable fields, and the rendered message is
 * exactly the summaries joined with a blank line — there is no path where
 * the two disagree.
 */
#[Test]
#[Covers(VerificationFailed::class)]
#[Covers(Understudy::class)]
#[Covers(VerificationFailure::class)]
#[Covers(FailureKind::class)]
final class VerificationFailureTest
{
    #[AfterTest]
    public function tearDown(): void
    {
        Understudy::reset();
    }

    public function verifyReportsAnUnmetExpectationWithBoundsAndCalls(): void
    {
        $repository = Understudy::for(BookRepository::class);
        when(fn() => $repository->find(1))->returns(new Book('title'));
        $repository->find(1);
        $repository->find(1);

        $failure = $this->catch(fn() => verify(fn() => $repository->find(1), times: 1));

        Assert::same(count($failure->failures()), 1);

        $record = $failure->failures()[0];

        Assert::same($record->kind, FailureKind::UnmetExpectation);
        Assert::same($record->double, 'BookRepository');
        Assert::same($record->expectation, 'find(1)');
        Assert::same($record->expectedMinimum, 1);
        Assert::same($record->expectedMaximum, 1);
        Assert::same($record->actualCount, 2);
        Assert::same(count($record->observedCalls ?? []), 2);
        Assert::null($record->expectedCalls);
    }

    public function verifyAllReportsAnUnmetExpectationClaim(): void
    {
        $repository = Understudy::for(BookRepository::class);

        expect(fn() => $repository->count())->times(2);

        $repository->count();

        $record = $this->firstFailureOf(fn() => Understudy::verifyAll());

        Assert::same($record->kind, FailureKind::UnmetExpectation);
        Assert::same($record->expectation, 'count()');
        Assert::same($record->expectedMinimum, 2);
        Assert::same($record->expectedMaximum, 2);
        Assert::same($record->actualCount, 1);
    }

    public function strictStubsReportsAnUnusedStub(): void
    {
        $repository = Understudy::for(BookRepository::class);
        when(fn() => $repository->find(7))->returns(new Book('title'));

        $record = $this->firstFailureOf(fn() => Understudy::verifyAll(strictStubs: true));

        Assert::same($record->kind, FailureKind::StrictStubUnused);
        Assert::same($record->expectation, 'find(7)');
        Assert::same($record->actualCount, 0);
    }

    public function orderingViolationsReportTheCallThatCameFirst(): void
    {
        $repository = Understudy::for(BookRepository::class);

        expect(fn() => $repository->count())->ordered();
        expect(fn() => $repository->titles())->ordered();

        $repository->titles();
        $repository->count();

        $record = $this->firstFailureOf(fn() => Understudy::verifyAll());

        Assert::same($record->kind, FailureKind::OutOfOrder);
        Assert::same($record->double, 'BookRepository');
        Assert::same($record->expectation, 'titles()');
    }

    public function nothingElseReportsUnaccountedCalls(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('title');
        when(fn() => $repository->save(Arg::any()))->returns(true);
        $repository->save($book);

        $record = $this->firstFailureOf(fn() => Understudy::nothingElse($repository));

        Assert::same($record->kind, FailureKind::UnaccountedCalls);
        Assert::same($record->actualCount, 1);
        Assert::same($record->observedCalls[0]->args, [$book]);
    }

    public function unusedReportsEveryCallWithZeroBounds(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $repository->count();

        $record = $this->firstFailureOf(fn() => Understudy::unused($repository));

        Assert::same($record->kind, FailureKind::UnusedDouble);
        Assert::same($record->expectedMinimum, 0);
        Assert::same($record->expectedMaximum, 0);
        Assert::same($record->actualCount, 1);
    }

    public function verifySequenceReportsExpectedAndObservedProtocol(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('title');

        $repository->save($book);
        $repository->count();

        $record = $this->firstFailureOf(fn() => Understudy::verifySequence(
            fn() => $repository->count(),
            fn() => $repository->save($book),
        ));

        Assert::same($record->kind, FailureKind::OutOfSequence);
        Assert::same($record->expectedCalls, ['count()', 'save(' . Book::class . "#1 {title: 'title'})"]);
        Assert::same($record->actualCount, 2);
        Assert::same(count($record->observedCalls ?? []), 2);
        Assert::null($record->double);
    }

    public function allVerifiedReportsBothHalvesInMessageOrder(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('title');

        expect(fn() => $repository->count())->times(2);
        $repository->save($book);

        $failure = $this->catch(fn() => Understudy::allVerified($repository));

        Assert::same(count($failure->failures()), 2);
        Assert::same($failure->failures()[0]->kind, FailureKind::UnmetExpectation);
        Assert::same($failure->failures()[1]->kind, FailureKind::UnaccountedCalls);
    }

    public function theMessageIsTheSummariesJoinedWithABlankLine(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('title');

        expect(fn() => $repository->count())->times(2);
        $repository->save($book);

        $failure = $this->catch(fn() => Understudy::allVerified($repository));

        Assert::same(
            $failure->getMessage(),
            implode("\n\n", array_map(
                static fn($record): string => $record->summary,
                $failure->failures(),
            )),
        );
    }

    public function oneMessageNumbersObjectsAcrossAllItsSummaries(): void
    {
        // `getMessage()` joins the summaries, so the alias table is the
        // report's, not each failure's: two `Book#1` on one screen would say
        // one object where there are two.
        $repository = Understudy::for(BookRepository::class);
        $dune = new Book('Dune');
        $herbert = new Book('Herbert');

        expect(fn() => $repository->save($dune))->times(2);

        $repository->save($dune);
        $repository->save($herbert);

        $message = $this->catch(fn() => Understudy::allVerified($repository))->getMessage();

        Assert::string($message)->contains('save(' . Book::class . "#1 {title: 'Dune'})");
        Assert::string($message)->contains('save(' . Book::class . "#2 {title: 'Herbert'})");
    }

    private function catch(callable $body): VerificationFailed
    {
        try {
            $body();
        } catch (VerificationFailed $failure) {
            return $failure;
        }

        throw new \LogicException('the verification was expected to fail');
    }

    private function firstFailureOf(callable $body): VerificationFailure
    {
        return $this->catch($body)->failures()[0];
    }

    // --- The structured fields say exactly what happened ----------------------

    /**
     * A strict-stub failure is a claim about ZERO use: the record carries
     * `actualCount: 0`, and the message is asserted whole — every half of a
     * concatenation in one is a mutant `contains()` cannot see.
     */
    public function aStrictStubFailureCarriesZeroAndSaysItWhole(): void
    {
        $repository = Understudy::for(BookRepository::class);
        Understudy::label($repository, 'catalogue');
        when(fn() => $repository->count())->returns(1);

        try {
            Understudy::verifyAll(strictStubs: true);
            Assert::true(actual: false);
        } catch (VerificationFailed $failed) {
            $failure = $failed->failures()[0];

            Assert::same($failure->actualCount, 0);
            Assert::same(
                $failure->summary,
                "Understudy `catalogue` has a stub for `count()` that was never used.\n"
                . 'Remove it, or drop strictStubs if the call is genuinely optional.',
            );
        }
    }

    /**
     * `unused()` claims a closed interval of exactly zero: both bounds are 0,
     * and the observed calls are the ones that broke the claim.
     */
    public function anUnusedFailureClaimsExactlyZero(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $repository->count();

        try {
            Understudy::unused($repository);
            Assert::true(actual: false);
        } catch (VerificationFailed $failed) {
            $failure = $failed->failures()[0];

            Assert::same($failure->expectedMinimum, 0);
            Assert::same($failure->expectedMaximum, 0);
            Assert::same($failure->actualCount, 1);
        }
    }

    /**
     * A failed `verify()` reports the calls of THE method it asked about:
     * calls to other methods are noise the reader should not wade through.
     */
    public function aVerifyFailureObservesOnlyTheAskedMethod(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $repository->count();
        $repository->titles();

        try {
            verify(fn() => $repository->count(), times: 5);
            Assert::true(actual: false);
        } catch (VerificationFailed $failed) {
            $observed = $failed->failures()[0]->observedCalls;

            Assert::same(count($observed), 1);
            Assert::same($observed[0]->method, 'count');
        }
    }

    /**
     * `allVerified()` reports BOTH halves when both are broken — the unmet
     * claim and the unaccounted call — not whichever it met first.
     */
    public function allVerifiedReportsEveryBrokenHalf(): void
    {
        $repository = Understudy::for(BookRepository::class);
        expect(fn() => $repository->count());

        $repository->titles();

        try {
            Understudy::allVerified($repository);
            Assert::true(actual: false);
        } catch (VerificationFailed $failed) {
            Assert::same(count($failed->failures()), 2);
        }
    }

    /**
     * A sequence mismatch renders its protocol as DESCRIPTIONS — strings a
     * reporter can print — not as the probe machinery that produced them.
     */
    public function aSequenceFailureDescribesTheExpectedCallsAsStrings(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $repository->count();

        try {
            Understudy::verifySequence(
                fn() => $repository->count(),
                fn() => $repository->titles(),
            );
            Assert::true(actual: false);
        } catch (VerificationFailed $failed) {
            Assert::same($failed->failures()[0]->expectedCalls, ['count()', 'titles()']);
        }
    }
}
