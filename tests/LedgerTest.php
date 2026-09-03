<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Exception\ContextOwnershipViolation;
use Rasuvaeff\Understudy\Exception\ForgottenDouble;
use Rasuvaeff\Understudy\Exception\InvalidCallSpecification;
use Rasuvaeff\Understudy\Exception\VerificationFailed;
use Rasuvaeff\Understudy\Expectation\Expectation;
use Rasuvaeff\Understudy\FailureKind;
use Rasuvaeff\Understudy\FailureReport;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Outcome;
use Rasuvaeff\Understudy\Runtime\DoubleState;
use Rasuvaeff\Understudy\Runtime\Runtime;
use Rasuvaeff\Understudy\Runtime\RuntimeContext;
use Rasuvaeff\Understudy\Tests\Fixture\Book;
use Rasuvaeff\Understudy\Tests\Fixture\BookRepository;
use Rasuvaeff\Understudy\Tests\Fixture\Clock;
use Rasuvaeff\Understudy\Tests\Fixture\Fwd\Chainable;
use Rasuvaeff\Understudy\Tests\Fixture\Fwd\RealChain;
use Rasuvaeff\Understudy\Tests\Fixture\Ref\Registry;
use Rasuvaeff\Understudy\Tests\Support\GoldenMessage;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Understudy\VerificationFailure;
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
    public function severalCleanDoublesPassTogether(): void
    {
        $first = Understudy::for(BookRepository::class);
        $second = Understudy::for(Clock::class);

        // One instance in both places: arguments match by identity.
        $book = new Book('Dune');

        expect(fn() => $first->save($book));
        $first->save($book);

        Understudy::nothingElse($first, $second);
    }

    public function anOffenderAmongSeveralIsNamed(): void
    {
        $clean = Understudy::for(Clock::class);
        $offender = Understudy::for(BookRepository::class);

        $offender->tag('alpha');

        Expect::exception(VerificationFailed::class)
            ->withMessageContaining('nothing accounted for');

        Understudy::nothingElse($clean, $offender);
    }

    public function severalOffendersAreReportedInOneFailure(): void
    {
        $first = Understudy::for(BookRepository::class);
        $second = Understudy::for(Clock::class);

        $first->tag('alpha');
        $first->tag('beta');
        // Clock has no calls at all; the second offender comes from another
        // double of the same contract, to prove the walk is per double.
        $third = Understudy::for(BookRepository::class);
        $third->count();

        // Asserted rather than declared: this test carried BOTH
        // `#[ExpectNoAssertions]` and `Expect::exception()`, which Testo calls
        // contradictory and reports as Risky. The suite had shipped one
        // permanent Risky ever since, which is how a status stops meaning
        // anything.
        try {
            Understudy::nothingElse($first, $second, $third);

            Assert::fail('Expected both offenders to be reported');
        } catch (VerificationFailed $failure) {
            Assert::string($failure->getMessage())
                ->contains('`BookRepository`')
                ->contains('2 call(s) nothing accounted for')
                ->contains('count()');
        }
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

    // --- expectSequence: an armed protocol -----------------------------------

    #[ExpectNoAssertions]
    public function anArmedProtocolPassesWhenTheStepsHappenInOrder(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('Dune');

        Understudy::expectSequence(
            fn() => $repository->save($book),
            fn() => $repository->count(),
        );

        $repository->save($book);
        $repository->count();

        Understudy::verifyAll();
    }

    public function anArmedProtocolFailsOnTheCallThatBrokeTheOrder(): void
    {
        // The whole point: the subject's own frame is on top of the stack,
        // rather than `verifyAll()`'s in teardown.
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('Dune');

        when(fn() => $repository->count())->returns(1);
        Understudy::expectSequence(
            fn() => $repository->save($book),
            fn() => $repository->count(),
        );

        Expect::exception(VerificationFailed::class)->withMessage(
            GoldenMessage::read('armed-protocol-refuses-a-call-out-of-turn'),
        );

        $repository->count();
    }

    public function aRefusedCallIsNotCountedAndNotAnswered(): void
    {
        // The refusal happens before anything answers: a call rejected after
        // `recordMatch()` and `performAction()` would leave the double moved
        // by a call that was refused.
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('Dune');
        $answered = 0;

        when(fn() => $repository->count())->answers(static function () use (&$answered): int {
            ++$answered;

            return 1;
        });
        Understudy::expectSequence(
            fn() => $repository->save($book),
            fn() => $repository->count(),
        );

        try {
            $repository->count();
        } catch (VerificationFailed) {
            // The subject may swallow it; the claim below is what still holds.
        }

        // The action never ran, and the stub never counted the call.
        Assert::same($answered, 0);
        Assert::string(Understudy::transcript($repository))->contains('count() ->');

        // Recorded, though: a refusal the transcript cannot show is a refusal
        // the reader has to reconstruct.
        Assert::same(count(Understudy::calls(fn() => $repository->count())), 1);
    }

    public function aStepRepeatedAfterTheProtocolMovedOnIsOutOfTurn(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('Dune');

        Understudy::expectSequence(
            fn() => $repository->save($book),
            fn() => $repository->count(),
        );

        $repository->save($book);

        Expect::exception(VerificationFailed::class)->withMessageContaining('out of turn');

        $repository->save($book);
    }

    #[ExpectNoAssertions]
    public function aConfiguredCallBetweenStepsIsBackground(): void
    {
        // A query the subject makes on the way has to be stubbed: without a
        // `when()` the protocol cannot tell "not part of this" from "you got
        // the order wrong".
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('Dune');

        when(fn() => $repository->titles())->returns([]);
        Understudy::expectSequence(
            fn() => $repository->save($book),
            fn() => $repository->count(),
        );

        $repository->save($book);
        $repository->titles();
        $repository->count();

        Understudy::verifyAll();
    }

    public function anUnconfiguredCallOnADoubleUnderProtocolIsRefused(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('Dune');

        Understudy::expectSequence(
            fn() => $repository->save($book),
            fn() => $repository->count(),
        );

        Expect::exception(VerificationFailed::class)->withMessageContaining(
            'neither a step nor configured',
        );

        $repository->titles();
    }

    #[ExpectNoAssertions]
    public function aDoubleTheProtocolDoesNotNameIsInvisibleToIt(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $other = Understudy::for(BookRepository::class);
        $book = new Book('Dune');

        Understudy::expectSequence(
            fn() => $repository->save($book),
            fn() => $repository->count(),
        );

        $other->titles();
        $repository->save($book);
        $other->titles();
        $repository->count();

        Understudy::verifyAll();
    }

    public function anUnfinishedProtocolIsReportedByVerifyAll(): void
    {
        // Arming is a claim, not only a guard — otherwise a subject that
        // stopped after step one, or swallowed the refusal, passes in silence.
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('Dune');

        Understudy::expectSequence(
            fn() => $repository->save($book),
            fn() => $repository->count(),
        );

        $repository->save($book);

        Expect::exception(VerificationFailed::class)->withMessageContaining(
            'The armed protocol stopped at step 2 of 2',
        );

        Understudy::verifyAll();
    }

    public function aSwallowedRefusalStillFailsTheTest(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('Dune');

        when(fn() => $repository->count())->returns(1);
        Understudy::expectSequence(
            fn() => $repository->save($book),
            fn() => $repository->count(),
        );

        try {
            $repository->count();
        } catch (VerificationFailed) {
            // A subject with a broad `catch` is exactly why the claim exists.
        }

        Expect::exception(VerificationFailed::class)->withMessageContaining('stopped at step 1 of 2');

        Understudy::verifyAll();
    }

    #[ExpectNoAssertions]
    public function aStepIsAccountedForByTheProtocolThatNamedIt(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('Dune');

        Understudy::expectSequence(
            fn() => $repository->save($book),
            fn() => $repository->count(),
        );

        $repository->save($book);
        $repository->count();

        Understudy::nothingElse($repository);
    }

    public function armingASecondProtocolWhileOneIsRunningIsRefused(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('Dune');

        Understudy::expectSequence(fn() => $repository->save($book), fn() => $repository->count());

        Expect::exception(InvalidCallSpecification::class)->withMessageContaining(
            'already armed and is waiting on step 1 of 2',
        );

        Understudy::expectSequence(fn() => $repository->titles());
    }

    #[ExpectNoAssertions]
    public function aFinishedProtocolCanBeReplacedByTheNextPhase(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('Dune');

        Understudy::expectSequence(fn() => $repository->save($book));
        $repository->save($book);

        Understudy::expectSequence(fn() => $repository->count());
        $repository->count();

        Understudy::verifyAll();
    }

    public function aStepAfterTheProtocolRanOutIsStillOutOfTurn(): void
    {
        // The protocol has no step due, so the report has to say that rather
        // than name one.
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('Dune');

        Understudy::expectSequence(fn() => $repository->save($book));
        $repository->save($book);

        $failure = null;

        try {
            $repository->save($book);
        } catch (VerificationFailed $thrown) {
            $failure = $thrown->failures()[0];
        }

        Assert::instanceOf($failure, VerificationFailure::class);
        Assert::same($failure->kind, FailureKind::OutOfSequence);
        Assert::null($failure->expectation);
        Assert::string($failure->summary)->contains('nothing — the protocol has run out');
    }

    public function anUnconfiguredCallAfterTheProtocolRanOutIsStillRefused(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('Dune');

        Understudy::expectSequence(fn() => $repository->save($book));
        $repository->save($book);

        $failure = null;

        try {
            $repository->titles();
        } catch (VerificationFailed $thrown) {
            $failure = $thrown->failures()[0];
        }

        Assert::instanceOf($failure, VerificationFailure::class);
        Assert::null($failure->expectation);
    }

    public function aProtocolRefusalCarriesTheCallAndTheStepsAsStructuredFields(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('Dune');

        when(fn() => $repository->count())->returns(1);
        Understudy::expectSequence(
            fn() => $repository->save($book),
            fn() => $repository->count(),
        );

        $failure = null;

        try {
            $repository->count();
        } catch (VerificationFailed $thrown) {
            $failure = $thrown->failures()[0];
        }

        Assert::instanceOf($failure, VerificationFailure::class);
        Assert::same($failure->double, 'BookRepository');
        Assert::same($failure->expectation, 'save(' . Book::class . "#1 {title: 'Dune'})");
        Assert::same($failure->expectedCalls, ['save(' . Book::class . "#1 {title: 'Dune'})", 'count()']);
        Assert::same(count($failure->observedCalls ?? []), 1);
        Assert::same(($failure->observedCalls ?? [])[0]->method, 'count');
    }

    public function aRefusalForAnUnconfiguredCallCarriesTheSameFields(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('Dune');

        Understudy::expectSequence(
            fn() => $repository->save($book),
            fn() => $repository->count(),
        );

        $failure = null;

        try {
            $repository->titles();
        } catch (VerificationFailed $thrown) {
            $failure = $thrown->failures()[0];
        }

        Assert::instanceOf($failure, VerificationFailure::class);
        Assert::same($failure->summary, GoldenMessage::read('armed-protocol-refuses-an-unconfigured-call'));
        Assert::same($failure->expectation, 'save(' . Book::class . "#1 {title: 'Dune'})");
        Assert::same($failure->expectedCalls, ['save(' . Book::class . "#1 {title: 'Dune'})", 'count()']);
        Assert::same(count($failure->observedCalls ?? []), 1);
        Assert::same(($failure->observedCalls ?? [])[0]->method, 'titles');
    }

    public function anUnfinishedProtocolIsReportedWithEveryStepNumbered(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('Dune');

        Understudy::expectSequence(
            fn() => $repository->save($book),
            fn() => $repository->count(),
            fn() => $repository->titles(),
        );

        $repository->save($book);

        $failure = null;

        try {
            Understudy::verifyAll();
        } catch (VerificationFailed $thrown) {
            $failure = $thrown->failures()[0];
        }

        Assert::instanceOf($failure, VerificationFailure::class);
        Assert::same($failure->summary, GoldenMessage::read('armed-protocol-stopped-halfway'));
        Assert::same($failure->expectation, 'count()');
    }

    public function aProtocolCannotBeArmedOnAnEnclosingDouble(): void
    {
        // Same rule as configuring one: a double belongs to the context that
        // created it, and only ordinary calls cross the boundary.
        $outer = Understudy::for(BookRepository::class);

        Expect::exception(ContextOwnershipViolation::class);

        Understudy::scope(static function () use ($outer): void {
            Understudy::expectSequence(fn() => $outer->count());
        });
    }

    public function anEmptyProtocolIsRefused(): void
    {
        Expect::exception(InvalidCallSpecification::class)->withMessageContaining(
            'needs at least one call',
        );

        Understudy::expectSequence();
    }

    #[ExpectNoAssertions]
    public function aCheckpointVerifiesTheProtocolAndThenDropsIt(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('Dune');

        Understudy::expectSequence(fn() => $repository->save($book));
        $repository->save($book);

        Understudy::checkpoint();

        // Dropped: an unconfigured call on that double would have been refused
        // a moment ago, because a protocol was watching it. The phase that
        // claimed the protocol is closed, so it is just a call again.
        $repository->titles();

        Understudy::verifyAll();
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

    /**
     * An empty `verifySequence()` asserts that nothing happened, where an
     * empty `expectSequence()` is refused. The asymmetry is the difference
     * between reading a log that exists and arming a protocol that would put
     * every later call on trial with no step to try it against — pinned here
     * so the two are not "made consistent" by accident.
     */
    #[ExpectNoAssertions]
    public function anEmptyVerifySequenceAssertsThatNothingHappened(): void
    {
        Understudy::for(BookRepository::class);

        Understudy::verifySequence();
    }

    public function anEmptyVerifySequenceFailsOnACallThatDidHappen(): void
    {
        $repository = Understudy::for(BookRepository::class);

        $repository->count();

        Expect::exception(VerificationFailed::class)->withMessageContaining('exactly 0 call(s)');

        Understudy::verifySequence();
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

    public function anOrderComplaintNamesTwoObjectsApart(): void
    {
        // The earlier expectation is described a loop turn before the message
        // quotes it. Described on its own it would start numbering again, and
        // the reader would be told that one `Book#1` happened before another
        // `Book#1`.
        $repository = Understudy::for(BookRepository::class);
        $dune = new Book('Dune');
        $herbert = new Book('Herbert');

        expect(fn() => $repository->save($dune))->ordered();
        expect(fn() => $repository->save($herbert))->ordered();

        $repository->save($herbert);
        $repository->save($dune);

        $failure = null;

        try {
            Understudy::verifyAll();
        } catch (VerificationFailed $thrown) {
            $failure = $thrown;
        }

        Assert::instanceOf($failure, VerificationFailed::class);
        Assert::string($failure->getMessage())->contains(
            sprintf(
                'expected `save(%s#2 {title: \'Herbert\'})` to be called after `save(%s#1 {title: \'Dune\'})`',
                Book::class,
                Book::class,
            ),
        );
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

    // --- What settle() keeps and what it drops --------------------------------

    /**
     * A satisfied claim is DONE and settle drops it: a call after the
     * checkpoint must not be counted against the old cardinality, or a phase
     * boundary would turn a clean second phase into "called 2 times".
     */
    #[ExpectNoAssertions]
    public function aSettledClaimDoesNotCountTheNextPhasesCalls(): void
    {
        $repository = Understudy::for(BookRepository::class);
        expect(fn() => $repository->count())->returns(1);

        $repository->count();
        Understudy::checkpoint();

        $repository->count();
        Understudy::verifyAll();
    }

    /**
     * Settle rebuilds the per-method map by APPENDING: two surviving stubs of
     * one method must both come through, or the older fallback silently
     * vanishes at every phase boundary.
     */
    public function settleKeepsEveryStubOfAMethod(): void
    {
        $repository = Understudy::for(BookRepository::class);
        when(fn() => $repository->find(1))->returns(new Book('one'));
        when(fn() => $repository->find(2))->returns(new Book('two'));

        Understudy::checkpoint();

        Assert::same($repository->find(1)?->title, 'one');
        Assert::same($repository->find(2)?->title, 'two');
    }

    // --- The literal index keeps the walked fallbacks reachable ---------------

    /**
     * A matcher-first specification has no literal key and must stay in the
     * walked list: once a second expectation puts the method under the index,
     * the wildcard still answers the calls the literal one does not.
     */
    public function aWildcardStubStaysReachableUnderTheLiteralIndex(): void
    {
        $repository = Understudy::for(BookRepository::class);
        when(fn() => $repository->find(Arg::any()))->returns(new Book('fallback'));
        when(fn() => $repository->find(2))->returns(new Book('exact'));

        Assert::same($repository->find(2)?->title, 'exact');
        Assert::same($repository->find(3)?->title, 'fallback');
    }

    // --- Fiber routing beyond plain dispatch ----------------------------------

    /**
     * A by-reference return from another Fiber still finds the OWNER's slot:
     * both lookups on that path route by owner first, and a context that only
     * exists inside the Fiber knows nothing about the double.
     */
    public function aByReferenceReturnFromAnotherFiberUsesTheOwnersSlot(): void
    {
        $registry = Understudy::for(Registry::class);

        $fiber = new \Fiber(static function () use ($registry): void {
            $slot = &$registry->values();
            $slot[] = 'written in the fiber';
        });
        $fiber->start();

        Assert::same($registry->values(), ['written in the fiber']);
    }

    /**
     * `callOriginal()` inside an answer, reached from another Fiber, delegates
     * through the owner's state — the running Fiber's empty context is not
     * where the forwarding target lives.
     */
    public function callOriginalFromAnotherFiberFindsTheOwnersTarget(): void
    {
        $real = new RealChain();
        $double = Understudy::for(Chainable::class);
        Understudy::forwarding($double, $real);
        when(fn(): string => $double->label())
            ->answers(static fn(Invocation $call): string => strtoupper((string) $call->callOriginal()));

        $answered = null;
        $fiber = new \Fiber(static function () use ($double, &$answered): void {
            $answered = $double->label();
        });
        $fiber->start();

        Assert::same($answered, 'REAL');
    }

    /**
     * A forgotten double's by-reference method fails with the same
     * `ForgottenDouble` a plain call gets — not with a type error from the
     * lookup that ran before dispatch could say it properly.
     */
    public function aByReferenceCallOnAForgottenDoubleSaysForgotten(): void
    {
        $registry = Understudy::for(Registry::class);
        Understudy::reset();

        Expect::exception(ForgottenDouble::class);

        $registry->values();
    }

    /**
     * Ordering claims of a Fiber-owned context are judged against THAT
     * context's own counting: two ordered expectations satisfied in reverse
     * inside a Fiber must fail `verifyAll()` called from the main flow.
     */
    public function orderingInsideAFiberIsJudgedByItsOwnContext(): void
    {
        $fiber = new \Fiber(static function (): void {
            $repository = Understudy::for(BookRepository::class);
            expect(fn() => $repository->count())->ordered()->returns(1);
            expect(fn() => $repository->titles())->ordered()->returns([]);

            $repository->titles();
            $repository->count();
            \Fiber::suspend();
        });
        $fiber->start();

        Expect::exception(VerificationFailed::class)
            ->withMessageContaining('to be called after');

        try {
            Understudy::verifyAll();
        } finally {
            $fiber->resume();
        }
    }

    // --- Outcome recording is first-writer-wins ------------------------------

    /**
     * An invocation's outcome is written once, by whoever answers first, and
     * every later writer is a no-op — including the lean discard, and
     * including the legacy Outcome wrapper, in both directions.
     */
    public function anOutcomeIsRecordedExactlyOnce(): void
    {
        $returned = new Invocation('m', [], 1);
        $returned->recordReturned('first');
        $returned->recordThrown(new \DomainException('late'));
        $returned->recordDiscardedReturn();

        Assert::true($returned->didReturn());
        Assert::false($returned->didThrow());
        Assert::same($returned->returned(), 'first');

        $threw = new Invocation('m', [], 2);
        $threw->recordThrown(new \DomainException('kept'));
        $threw->recordReturned('late');

        Assert::true($threw->didThrow());
        Assert::instanceOf($threw->thrown(), \DomainException::class);

        $legacy = new Invocation('m', [], 3);
        $legacy->recordOutcome(Outcome::returnedValue('wrapped'));
        $legacy->recordReturned('late');

        Assert::true($legacy->didReturn());
        Assert::false($legacy->didThrow());
        Assert::same($legacy->returned(), 'wrapped');

        $legacyThrew = new Invocation('m', [], 4);
        $legacyThrew->recordOutcome(Outcome::thrownError(new \DomainException('wrapped')));

        Assert::false($legacyThrew->didReturn());
        Assert::true($legacyThrew->didThrow());
    }

    // --- The context answers plainly for its own state ------------------------

    public function aFreshContextIsNotRecordingAndHoldsNoCaptors(): void
    {
        $context = new RuntimeContext();

        Assert::false($context->isRecording());
        Assert::same($context->captors(), []);
    }

    /**
     * Retirement walks EVERY double the context held: after a reset, both are
     * forgotten, not just the first one the storage yielded.
     */
    public function aResetRetiresEveryDoubleOfTheContext(): void
    {
        $first = Understudy::for(BookRepository::class);
        $second = Understudy::for(BookRepository::class);

        Understudy::reset();

        foreach ([$first, $second] as $double) {
            try {
                $double->count();
                Assert::true(actual: false);
            } catch (ForgottenDouble) {
                Assert::true(actual: true);
            }
        }
    }

    // --- scope and checkpoint ------------------------------------------------

    public function scopeReturnsWhatTheCallbackReturns(): void
    {
        Assert::same(Understudy::scope(static fn(): string => 'value'), 'value');
    }

    /**
     * A closed scope is gone from accounting, not merely emptied: a later
     * `verifyAll(strictStubs: true)` must not dig up the retired context and
     * report its unused stub — the scope already answered for its own cast
     * when it closed.
     */
    #[ExpectNoAssertions]
    public function aClosedScopeIsInvisibleToLaterVerification(): void
    {
        Understudy::scope(static function (): void {
            $repository = Understudy::for(BookRepository::class);

            when(fn() => $repository->count())->returns(1);
        });

        Understudy::verifyAll(strictStubs: true);
    }

    public function scopeVerifiesOnSuccess(): void
    {
        Expect::exception(VerificationFailed::class)->withMessageContaining('never');

        Understudy::scope(static function (): void {
            $repository = Understudy::for(BookRepository::class);

            expect(fn() => $repository->count());
        });
    }

    /**
     * The regression behind issue #84: a scope is a local lifetime, and the
     * enclosing context is still running. Closing one used to sweep every
     * live context, so a self-contained scope failed for a claim the test had
     * simply not got round to satisfying yet.
     */
    public function aScopeDoesNotAnswerForTheEnclosingContextsClaims(): void
    {
        $outer = Understudy::for(BookRepository::class);
        expect(fn() => $outer->count());

        Understudy::scope(static function (): void {
            $inner = Understudy::for(BookRepository::class);
            expect(fn() => $inner->count());

            Assert::same($inner->count(), 0);
        });

        // Ignored, not forgiven: the claim the close passed over is exactly
        // the one an explicit verification still refuses.
        Expect::exception(VerificationFailed::class)->withMessageContaining('never');

        Understudy::verifyAll();
    }

    /**
     * The other half of the same rule: what the scope does own is still
     * judged, and the report names the scope's double rather than the
     * enclosing one whose claim is also open.
     */
    public function aScopeStillFailsOnItsOwnUnmetClaimUnderAViolatedOuterLedger(): void
    {
        $outer = Understudy::for(Clock::class);
        expect(fn() => $outer->now());

        $caught = null;

        try {
            Understudy::scope(static function (): void {
                $inner = Understudy::for(BookRepository::class);

                expect(fn() => $inner->count());
            });
        } catch (VerificationFailed $failure) {
            $caught = $failure;
        }

        \assert($caught instanceof VerificationFailed);
        Assert::string($caught->getMessage())->contains('BookRepository');
        Assert::string($caught->getMessage())->notContains('Clock');
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
            GoldenMessage::read('verify-all-two-unmet-expectations-in-declaration-order'),
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
