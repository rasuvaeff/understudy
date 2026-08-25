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

        $failure = self::catch(fn() => verify(fn() => $repository->find(1), times: 1));

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

        $record = self::firstFailureOf(fn() => Understudy::verifyAll());

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

        $record = self::firstFailureOf(fn() => Understudy::verifyAll(strictStubs: true));

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

        $record = self::firstFailureOf(fn() => Understudy::verifyAll());

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

        $record = self::firstFailureOf(fn() => Understudy::nothingElse($repository));

        Assert::same($record->kind, FailureKind::UnaccountedCalls);
        Assert::same($record->actualCount, 1);
        Assert::same($record->observedCalls[0]->args, [$book]);
    }

    public function unusedReportsEveryCallWithZeroBounds(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $repository->count();

        $record = self::firstFailureOf(fn() => Understudy::unused($repository));

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

        $record = self::firstFailureOf(
            fn() => Understudy::verifySequence(
                fn() => $repository->count(),
                fn() => $repository->save($book),
            ),
        );

        Assert::same($record->kind, FailureKind::OutOfSequence);
        Assert::same($record->expectedCalls, ['count()', 'save(' . Book::class . ')']);
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

        $failure = self::catch(fn() => Understudy::allVerified($repository));

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

        $failure = self::catch(fn() => Understudy::allVerified($repository));

        Assert::same(
            $failure->getMessage(),
            implode("\n\n", array_map(
                static fn($record): string => $record->summary,
                $failure->failures(),
            )),
        );
    }

    private static function catch(callable $body): VerificationFailed
    {
        try {
            $body();
        } catch (VerificationFailed $failure) {
            return $failure;
        }

        throw new \LogicException('the verification was expected to fail');
    }

    private static function firstFailureOf(callable $body): VerificationFailure
    {
        return self::catch($body)->failures()[0];
    }
}
