<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Exception\ContextOwnershipViolation;
use Rasuvaeff\Understudy\Exception\ForgottenDouble;
use Rasuvaeff\Understudy\Exception\VerificationFailed;
use Rasuvaeff\Understudy\Expectation\Expectation;
use Rasuvaeff\Understudy\FailureReport;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Outcome;
use Rasuvaeff\Understudy\Runtime\DoubleState;
use Rasuvaeff\Understudy\Runtime\Runtime;
use Rasuvaeff\Understudy\Runtime\RuntimeContext;
use Rasuvaeff\Understudy\Tests\Fixture\Book;
use Rasuvaeff\Understudy\Tests\Fixture\BookRepository;
use Rasuvaeff\Understudy\Tests\Fixture\Clock;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Assert\ExpectNoAssertions;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

use function Rasuvaeff\Understudy\expect;
use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

/**
 * The ledger answers one question — "has everything that happened been
 * described?" — so these tests are mostly about what counts as an answer.
 */
#[Test]
#[Covers(Understudy::class)]
#[Covers(Runtime::class)]
#[Covers(RuntimeContext::class)]
#[Covers(DoubleState::class)]
#[Covers(Expectation::class)]
#[Covers(Invocation::class)]
#[Covers(Outcome::class)]
#[Covers(FailureReport::class)]
#[Covers(ContextOwnershipViolation::class)]
#[Covers(ForgottenDouble::class)]
#[Covers(VerificationFailed::class)]
final class LedgerTest
{
    #[AfterTest]
    public function tearDown(): void
    {
        Understudy::reset();
    }

    // --- nothingElse ---------------------------------------------------------

    #[ExpectNoAssertions]
    public function anExpectationAccountsForTheCallItMatched(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('Dune');

        expect(fn() => $repository->save($book));
        $repository->save($book);

        Understudy::nothingElse($repository);
    }

    public function aStubDoesNotAccountForAnything(): void
    {
        // A stub is permission, not a description of what happened.
        $repository = Understudy::for(BookRepository::class);

        when(fn() => $repository->count())->returns(1);
        $repository->count();

        Expect::exception(VerificationFailed::class)->withMessageContaining('nothing accounted for');

        Understudy::nothingElse($repository);
    }

    #[ExpectNoAssertions]
    public function aSuccessfulVerifyAccountsForWhatItClaimed(): void
    {
        $repository = Understudy::for(BookRepository::class);

        when(fn() => $repository->count())->returns(1);
        $repository->count();

        verify(fn() => $repository->count());

        Understudy::nothingElse($repository);
    }

    public function expectationsForOneMethodDoNotScanAnotherMethod(): void
    {
        $repository = Understudy::for(BookRepository::class);

        when(fn() => $repository->count())->returns(7);
        when(fn() => $repository->titles())->returns(['Dune']);

        Assert::same($repository->count(), 7);
        Assert::same($repository->titles(), ['Dune']);
    }

    public function nothingElseNamesTheUnaccountedCalls(): void
    {
        $repository = Understudy::for(BookRepository::class);

        $repository->tag('alpha');
        $repository->tag('beta');

        verify(fn() => $repository->tag('alpha', 1));

        Expect::exception(VerificationFailed::class)
            ->withMessageContaining("tag('beta', 1)")
            ->withMessageContaining('1 call(s)');

        Understudy::nothingElse($repository);
    }

    #[ExpectNoAssertions]
    public function anUntouchedDoubleHasNothingUnaccounted(): void
    {
        Understudy::nothingElse(Understudy::for(BookRepository::class));
    }

    #[ExpectNoAssertions]
    public function repeatedVerificationIsIdempotent(): void
    {
        // Counting the whole log each time is what keeps the order of verify()
        // calls from changing the outcome.
        $repository = Understudy::for(BookRepository::class);

        $repository->count();

        verify(fn() => $repository->count());
        verify(fn() => $repository->count());
        verify(fn() => $repository->count(), times: 1);

        Understudy::nothingElse($repository);
    }

    public function aFailedVerifyAccountsForNothing(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $repository->count();

        try {
            Understudy::verify(fn() => $repository->count(), times: 5);
        } catch (VerificationFailed) {
            // The point is what the failure did NOT do.
        }

        Expect::exception(VerificationFailed::class)->withMessageContaining('nothing accounted for');

        Understudy::nothingElse($repository);
    }

    // --- allVerified ---------------------------------------------------------

    #[ExpectNoAssertions]
    public function allVerifiedPassesWhenEverythingIsDescribed(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('Dune');

        expect(fn() => $repository->save($book));
        $repository->save($book);

        Understudy::allVerified($repository);
    }

    public function allVerifiedFailsOnAnUnmetExpectation(): void
    {
        $repository = Understudy::for(BookRepository::class);

        expect(fn() => $repository->count())->times(2);
        $repository->count();

        Expect::exception(VerificationFailed::class)->withMessageContaining('exactly 2 times');

        Understudy::allVerified($repository);
    }

    public function allVerifiedFailsOnAnUndescribedCall(): void
    {
        $repository = Understudy::for(BookRepository::class);

        expect(fn() => $repository->count());
        $repository->count();
        $repository->titles();

        Expect::exception(VerificationFailed::class)->withMessageContaining('nothing accounted for');

        Understudy::allVerified($repository);
    }

    public function allVerifiedChecksOrderedExpectationsToo(): void
    {
        $repository = Understudy::for(BookRepository::class);

        expect(fn() => $repository->count())->ordered();
        expect(fn() => $repository->titles())->ordered();

        $repository->titles();
        $repository->count();

        Expect::exception(VerificationFailed::class)->withMessageContaining('but it happened first');

        Understudy::allVerified($repository);
    }

    // --- verifySequence ------------------------------------------------------

    #[ExpectNoAssertions]
    public function verifySequenceAcceptsTheExactProtocol(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('Dune');

        $repository->count();
        $repository->save($book);
        $repository->titles();

        Understudy::verifySequence(
            fn() => $repository->count(),
            fn() => $repository->save($book),
            fn() => $repository->titles(),
        );
    }

    #[ExpectNoAssertions]
    public function verifySequenceSpansSeveralDoubles(): void
    {
        // The order is one order across the whole context, which is the reason
        // the sequence counter lives there rather than on each double.
        $repository = Understudy::for(BookRepository::class);
        $clock = Understudy::for(Clock::class);

        $clock->now();
        $repository->count();

        Understudy::verifySequence(
            fn() => $clock->now(),
            fn() => $repository->count(),
        );
    }

    public function verifySequenceRejectsTheWrongOrder(): void
    {
        $repository = Understudy::for(BookRepository::class);

        $repository->titles();
        $repository->count();

        Expect::exception(VerificationFailed::class)
            ->withMessageContaining('Call #1')
            ->withMessageContaining('count()');

        Understudy::verifySequence(
            fn() => $repository->count(),
            fn() => $repository->titles(),
        );
    }

    public function verifySequenceRejectsAnExtraCall(): void
    {
        $repository = Understudy::for(BookRepository::class);

        $repository->count();
        $repository->titles();

        Expect::exception(VerificationFailed::class)->withMessageContaining('exactly 1 call(s)');

        Understudy::verifySequence(fn() => $repository->count());
    }

    #[ExpectNoAssertions]
    public function verifySequenceAccountsForTheWholeLog(): void
    {
        $repository = Understudy::for(BookRepository::class);

        $repository->count();
        $repository->titles();

        Understudy::verifySequence(
            fn() => $repository->count(),
            fn() => $repository->titles(),
        );

        Understudy::nothingElse($repository);
    }

    #[ExpectNoAssertions]
    public function verifySequenceAcceptsMatchers(): void
    {
        $repository = Understudy::for(BookRepository::class);

        $repository->tag('alpha', 2);

        Understudy::verifySequence(fn() => $repository->tag(Arg::any(), Arg::int(min: 1)));
    }

    public function verifySequenceDistinguishesDoublesOfTheSameContract(): void
    {
        $first = Understudy::for(BookRepository::class);
        $second = Understudy::for(BookRepository::class);

        $first->count();
        $second->count();

        Expect::exception(VerificationFailed::class)
            ->withMessageContaining('Call #1')
            ->withMessageContaining('different understudy');

        Understudy::verifySequence(
            fn() => $second->count(),
            fn() => $first->count(),
        );
    }

    public function verifySequenceRejectsADoubleOwnedByAnotherContext(): void
    {
        $repository = Understudy::for(BookRepository::class);

        Expect::exception(ContextOwnershipViolation::class)->withMessage(
            'This understudy belongs to a different runtime context. '
            . 'Configure and verify it in the scope or Fiber that created it; '
            . 'only normal method calls may cross context boundaries.',
        );

        Understudy::scope(
            static fn() => Understudy::verifySequence(fn() => $repository->count()),
        );
    }

    // --- ordered -------------------------------------------------------------

    #[ExpectNoAssertions]
    public function orderedExpectationsPassInDeclarationOrder(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('Dune');

        expect(fn() => $repository->count())->ordered();
        expect(fn() => $repository->save($book))->ordered();

        $repository->count();
        $repository->save($book);

        Understudy::verifyAll();
    }

    #[ExpectNoAssertions]
    public function unrelatedCallsMayHappenBetweenOrderedExpectations(): void
    {
        // ordered() constrains the expectations relative to each other, not
        // the whole protocol — verifySequence() is the tool for that.
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('Dune');

        expect(fn() => $repository->count())->ordered();
        expect(fn() => $repository->save($book))->ordered();

        $repository->count();
        $repository->titles();
        $repository->save($book);

        Understudy::verifyAll();
    }

    public function orderingFollowsDeclarationOrderAcrossDoubles(): void
    {
        // Expectations are stored per double, so checking them double by
        // double would read this as "clock, then repository" — the order the
        // doubles were registered in, not the order the claims were written.
        $repository = Understudy::for(BookRepository::class);
        $clock = Understudy::for(Clock::class);

        expect(fn() => $repository->count())->ordered();
        expect(fn() => $clock->now())->ordered();
        expect(fn() => $repository->titles())->ordered();

        $repository->count();
        $clock->now();
        $repository->titles();

        Understudy::verifyAll();

        Assert::true(actual: true);
    }

    public function interleavedOrderingFailsWhenViolated(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $clock = Understudy::for(Clock::class);

        expect(fn() => $repository->count())->ordered();
        expect(fn() => $clock->now())->ordered();

        $clock->now();
        $repository->count();

        Expect::exception(VerificationFailed::class)->withMessageContaining('but it happened first');

        Understudy::verifyAll();
    }

    public function orderedExpectationsFailWhenReversed(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('Dune');

        expect(fn() => $repository->count())->ordered();
        expect(fn() => $repository->save($book))->ordered();

        $repository->save($book);
        $repository->count();

        Expect::exception(VerificationFailed::class)->withMessageContaining('but it happened first');

        Understudy::verifyAll();
    }

    #[ExpectNoAssertions]
    public function unorderedExpectationsDoNotConstrainOrder(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('Dune');

        expect(fn() => $repository->count());
        expect(fn() => $repository->save($book));

        $repository->save($book);
        $repository->count();

        Understudy::verifyAll();
    }

    // --- scope and checkpoint ------------------------------------------------

    public function scopeReturnsWhatTheCallbackReturns(): void
    {
        Assert::same(Understudy::scope(static fn(): string => 'value'), 'value');
    }

    public function scopeVerifiesOnSuccess(): void
    {
        Expect::exception(VerificationFailed::class)->withMessageContaining('never');

        Understudy::scope(static function (): void {
            $repository = Understudy::for(BookRepository::class);

            expect(fn() => $repository->count());
        });
    }

    public function scopeDoesNotReplaceTheCallbacksOwnFailure(): void
    {
        // An unmet expectation inside a failing scope is a symptom; the
        // original failure is what the reader needs.
        Expect::exception(\DomainException::class)->withMessage('the real problem');

        Understudy::scope(static function (): void {
            $repository = Understudy::for(BookRepository::class);
            expect(fn() => $repository->count());

            throw new \DomainException('the real problem');
        });
    }

    public function aScopeLeavesTheEnclosingContextIntact(): void
    {
        $outer = Understudy::for(BookRepository::class);
        when(fn() => $outer->count())->returns(7);

        Understudy::scope(static function (): void {
            $inner = Understudy::for(BookRepository::class);

            Assert::same($inner->count(), 0);
        });

        Assert::same($outer->count(), 7);
    }

    public function aScopeRejectsConfigurationOfAnEnclosingDouble(): void
    {
        $outer = Understudy::for(BookRepository::class);

        Expect::exception(ContextOwnershipViolation::class)->withMessage(
            'This understudy belongs to a different runtime context. '
            . 'Configure and verify it in the scope or Fiber that created it; '
            . 'only normal method calls may cross context boundaries.',
        );

        Understudy::scope(static function () use ($outer): void {
            expect(fn() => $outer->count());
        });
    }

    public function aDoubleCannotOutliveItsScope(): void
    {
        $escaped = Understudy::scope(static fn(): BookRepository => Understudy::for(BookRepository::class));

        Expect::exception(ForgottenDouble::class)->withMessage(
            "This understudy is no longer known to Understudy, but `count()` was called on it.\n"
            . 'It was created before a reset(); create doubles inside the test that uses them '
            . 'rather than sharing one across tests.',
        );

        $escaped->count();
    }

    public function resetLeavesASiblingFiberContextIntact(): void
    {
        $resetter = new \Fiber(static function (): void {
            Understudy::for(BookRepository::class);
            \Fiber::suspend();
            Understudy::reset();
        });
        $worker = new \Fiber(static function (): void {
            $repository = Understudy::for(BookRepository::class);
            \Fiber::suspend();

            Assert::same($repository->count(), 0);
        });

        $resetter->start();
        $worker->start();
        $resetter->resume();
        $worker->resume();

        Assert::true($resetter->isTerminated());
        Assert::true($worker->isTerminated());
    }

    #[ExpectNoAssertions]
    public function checkpointClearsSettledWorkAndKeepsTheCast(): void
    {
        $repository = Understudy::for(BookRepository::class);

        expect(fn() => $repository->count());
        $repository->count();

        Understudy::checkpoint();

        // The same double carries on, with the first phase forgotten.
        expect(fn() => $repository->titles());
        $repository->titles();

        Understudy::verifyAll();
        Understudy::nothingElse($repository);
    }

    public function checkpointFailsOnAnUnmetExpectation(): void
    {
        $repository = Understudy::for(BookRepository::class);

        expect(fn() => $repository->count());

        Expect::exception(VerificationFailed::class)->withMessageContaining('never');

        Understudy::checkpoint();
    }

    public function checkpointKeepsPlainStubs(): void
    {
        $repository = Understudy::for(BookRepository::class);

        when(fn() => $repository->count())->returns(7);
        $repository->count();

        Understudy::checkpoint();

        Assert::same($repository->count(), 7);
    }

    // --- verifyAll reporting -------------------------------------------------

    public function verifyAllReportsEveryUnmetExpectationInOneMessage(): void
    {
        $repository = Understudy::for(BookRepository::class);

        expect(fn() => $repository->count());
        expect(fn() => $repository->titles());

        // Reported in the order the claims were written, which is not the
        // order the ledger keeps them in.
        Expect::exception(VerificationFailed::class)->withMessage(
            "Understudy `BookRepository` expected `count()` to be called exactly 1 time, but it was called never.\n\n"
            . 'Understudy `BookRepository` expected `titles()` to be called exactly 1 time, but it was called never.',
        );

        Understudy::verifyAll();
    }

    public function theActualCountIsSpelledOutInTheFailure(): void
    {
        $repository = Understudy::for(BookRepository::class);

        expect(fn() => $repository->count())->times(3);

        $repository->count();

        Expect::exception(VerificationFailed::class)->withMessage(
            'Understudy `BookRepository` expected `count()` to be called exactly 3 times, but it was called 1 time.',
        );

        Understudy::verifyAll();
    }

    public function aRepeatedCallIsCountedInThePlural(): void
    {
        $repository = Understudy::for(BookRepository::class);

        expect(fn() => $repository->count())->times(3);

        $repository->count();
        $repository->count();

        Expect::exception(VerificationFailed::class)->withMessage(
            'Understudy `BookRepository` expected `count()` to be called exactly 3 times, but it was called 2 times.',
        );

        Understudy::verifyAll();
    }

    #[ExpectNoAssertions]
    public function strictStubsAcceptsAStubThatWasUsed(): void
    {
        $repository = Understudy::for(BookRepository::class);

        when(fn() => $repository->count())->returns(7);
        $repository->count();

        Understudy::verifyAll(strictStubs: true);
    }

    #[ExpectNoAssertions]
    public function anUnusedStubIsFineWithoutStrictStubs(): void
    {
        $repository = Understudy::for(BookRepository::class);

        when(fn() => $repository->count())->returns(7);

        Understudy::verifyAll();
    }

    #[ExpectNoAssertions]
    public function allVerifiedIgnoresAnUnusedStub(): void
    {
        // `allVerified()` is about claims and stray calls, not about stubs
        // that turned out to be unnecessary.
        $repository = Understudy::for(BookRepository::class);

        when(fn() => $repository->count())->returns(7);
        expect(fn() => $repository->titles())->returns([]);

        $repository->titles();

        Understudy::allVerified($repository);
    }

    #[ExpectNoAssertions]
    public function allVerifiedIgnoresAnotherDoublesOrdering(): void
    {
        $primary = Understudy::for(BookRepository::class);
        $secondary = Understudy::for(BookRepository::class);

        expect(fn() => $secondary->count())->ordered();
        expect(fn() => $secondary->titles())->ordered();
        expect(fn() => $primary->count());

        $secondary->titles();
        $secondary->count();
        $primary->count();

        Understudy::allVerified($primary);
    }

    #[ExpectNoAssertions]
    public function checkpointRetiresTheExpectationsItSettled(): void
    {
        $repository = Understudy::for(BookRepository::class);

        expect(fn() => $repository->count());
        $repository->count();

        Understudy::checkpoint();

        // The claim was settled at the checkpoint: a later call is no longer
        // its business, so it cannot fail on the count.
        $repository->count();

        Understudy::verifyAll();
    }

    #[ExpectNoAssertions]
    public function aScopeIsLeftEvenWhenItsCallbackThrows(): void
    {
        $outer = Understudy::for(BookRepository::class);

        try {
            Understudy::scope(static function (): void {
                throw new \DomainException('the real problem');
            });
        } catch (\DomainException) {
            // The point is what happens next, not the exception itself.
        }

        // Still inside the scope, this would be a ContextOwnershipViolation.
        when(fn() => $outer->count())->returns(1);
    }

    #[ExpectNoAssertions]
    public function aScopeIsLeftEvenWhenItsOwnVerificationFails(): void
    {
        $outer = Understudy::for(BookRepository::class);

        try {
            Understudy::scope(static function (): void {
                $inner = Understudy::for(BookRepository::class);
                expect(fn() => $inner->count());
            });
        } catch (VerificationFailed) {
            // Same again: the scope must be popped before this is rethrown.
        }

        when(fn() => $outer->count())->returns(1);
    }

    public function allVerifiedFindsAnOrderingViolationOnALaterDouble(): void
    {
        // The double under verification is not the first one registered, so
        // the scan has to skip past the others rather than stop at them.
        $primary = Understudy::for(BookRepository::class);
        $secondary = Understudy::for(BookRepository::class);

        expect(fn() => $primary->count());
        expect(fn() => $secondary->count())->ordered();
        expect(fn() => $secondary->titles())->ordered();

        $primary->count();
        $secondary->titles();
        $secondary->count();

        Expect::exception(VerificationFailed::class)->withMessageContaining('but it happened first');

        Understudy::allVerified($secondary);
    }

    // --- fibers --------------------------------------------------------------

    public function aCallFromAnotherFiberIsRecordedByTheOwner(): void
    {
        $repository = Understudy::for(BookRepository::class);

        $fiber = new \Fiber(static function () use ($repository): void {
            $repository->count();
        });
        $fiber->start();

        Assert::same(count(Understudy::calls(fn() => $repository->count())), 1);
    }

    public function aResetInsideAFiberForgetsThatFibersDoubles(): void
    {
        $fiber = new \Fiber(static function (): BookRepository {
            $inside = Understudy::for(BookRepository::class);
            Understudy::reset();

            return $inside;
        });
        $fiber->start();
        /** @var BookRepository $escaped */
        $escaped = $fiber->getReturn();

        Expect::exception(ForgottenDouble::class)->withMessageContaining('count()');

        $escaped->count();
    }

    public function aResetInsideAFiberStartsTheCallOrderAgain(): void
    {
        $sequences = [];

        $fiber = new \Fiber(static function () use (&$sequences): void {
            $first = Understudy::for(BookRepository::class);
            $first->count();

            Understudy::reset();

            $second = Understudy::for(BookRepository::class);
            $second->count();

            /** @var list<int> $sequences */
            $sequences = array_map(
                static fn(Invocation $invocation): int => $invocation->sequence,
                Understudy::calls(fn() => $second->count()),
            );
        });
        $fiber->start();

        Assert::same($sequences, [1]);
    }

    public function scopesNestInsideAFiberLikeAnywhereElse(): void
    {
        $escaped = null;

        $fiber = new \Fiber(static function () use (&$escaped): void {
            $outer = Understudy::for(BookRepository::class);
            when(fn() => $outer->count())->returns(1);

            $escaped = Understudy::scope(static function (): Clock {
                $inner = Understudy::for(Clock::class);
                when(fn() => $inner->now())->returns(5);

                Assert::same($inner->now(), 5);

                return $inner;
            });

            Assert::same($outer->count(), 1);

            // Back in the fiber's own context: configuring the outer double
            // would be a ContextOwnershipViolation from inside the scope.
            when(fn() => $outer->titles())->returns(['Dune']);

            Assert::same($outer->titles(), ['Dune']);
        });
        $fiber->start();

        Assert::instanceOf($escaped, Clock::class);

        Expect::exception(ForgottenDouble::class)->withMessageContaining('now()');

        $escaped->now();
    }

    // --- invocations in flight -----------------------------------------------

    public function anInFlightInvocationHasNoOutcomeYet(): void
    {
        // `answers()` runs while the call is still in progress: the outcome
        // is filled in by the dispatcher only once the answer is known.
        $repository = Understudy::for(BookRepository::class);
        $seen = [];

        when(fn() => $repository->count())->answers(
            static function (Invocation $invocation) use (&$seen): int {
                $seen = [
                    'returned' => $invocation->didReturn(),
                    'threw' => $invocation->didThrow(),
                    'value' => $invocation->returned(),
                    'error' => $invocation->thrown(),
                ];

                return 7;
            },
        );

        Assert::same($repository->count(), 7);
        Assert::same($seen, ['returned' => false, 'threw' => false, 'value' => null, 'error' => null]);
    }

    public function aCheckpointDropsTheCallsItAccountedFor(): void
    {
        $repository = Understudy::for(BookRepository::class);

        expect(fn() => $repository->count());
        $repository->count();
        $repository->titles();

        Understudy::checkpoint();

        // The settled call is gone; the one nothing claimed is still there to
        // be explained.
        Assert::same(Understudy::calls(fn() => $repository->count()), []);
        Assert::same(count(Understudy::calls(fn() => $repository->titles())), 1);
    }

    #[ExpectNoAssertions]
    public function verifySequenceAfterACheckpointSeesOnlyTheNewCalls(): void
    {
        // The settled calls are dropped from the log, and what remains has to
        // be a list again — verifySequence() reads it by position.
        $repository = Understudy::for(BookRepository::class);

        expect(fn() => $repository->count());
        $repository->count();
        $repository->titles();

        Understudy::checkpoint();

        Understudy::verifySequence(fn() => $repository->titles());
    }

    // --- transcript ----------------------------------------------------------


    public function transcriptShowsEveryCallWithItsOutcome(): void
    {
        $repository = Understudy::for(BookRepository::class);
        Understudy::label($repository, 'catalogue');

        when(fn() => $repository->count())->returns(7);
        when(fn() => $repository->titles())->throws(new \RuntimeException('gone'));

        $repository->count();

        try {
            $repository->titles();
        } catch (\RuntimeException) {
            // Recorded either way.
        }

        $repository->tag('alpha');

        $transcript = Understudy::transcript($repository);

        // Asserted whole: the transcript is read by a human looking for the
        // call that surprised them, so every part of the line is contract.
        Assert::same(
            $transcript,
            "Understudy `catalogue` received 3 call(s):\n"
            . "  #1 count() -> returned 7\n"
            . "  #2 titles() -> threw RuntimeException\n"
            . "  #3 tag('alpha', 1) -> returned ''",
        );
    }

    public function transcriptSaysSoWhenNothingHappened(): void
    {
        $transcript = Understudy::transcript(Understudy::for(BookRepository::class));

        Assert::string($transcript)->contains('no calls');
    }
}
