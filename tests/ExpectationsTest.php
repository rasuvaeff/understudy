<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Exception\NeverMethodCalled;
use Rasuvaeff\Understudy\Exception\VerificationFailed;
use Rasuvaeff\Understudy\Expectation\ComputeAnswer;
use Rasuvaeff\Understudy\Expectation\Expectation;
use Rasuvaeff\Understudy\Expectation\ReturnValue;
use Rasuvaeff\Understudy\Expectation\ThrowError;
use Rasuvaeff\Understudy\ExpectBuilder;
use Rasuvaeff\Understudy\FailureReport;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Runtime\DoubleState;
use Rasuvaeff\Understudy\Runtime\Runtime;
use Rasuvaeff\Understudy\Tests\Fixture\Book;
use Rasuvaeff\Understudy\Tests\Fixture\BookRepository;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Understudy\WhenBuilder;
use Testo\Assert;
use Testo\Assert\ExpectNoAssertions;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

use function Rasuvaeff\Understudy\expect;
use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(ExpectBuilder::class)]
#[Covers(WhenBuilder::class)]
#[Covers(Runtime::class)]
#[Covers(DoubleState::class)]
#[Covers(Expectation::class)]
#[Covers(ReturnValue::class)]
#[Covers(ThrowError::class)]
#[Covers(ComputeAnswer::class)]
#[Covers(Invocation::class)]
#[Covers(FailureReport::class)]
#[Covers(NeverMethodCalled::class)]
#[Covers(VerificationFailed::class)]
final class ExpectationsTest
{
    #[AfterTest]
    public function tearDown(): void
    {
        Understudy::reset();
    }

    // --- Action chains -------------------------------------------------------

    public function thenDescribesWhatTheNextCallDoes(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $first = new Book('first');

        when(fn() => $repository->find(1))
            ->returns($first)
            ->then()->throws(new \RuntimeException('gone'));

        Assert::same($repository->find(1), $first);

        Expect::exception(\RuntimeException::class);

        $repository->find(1);
    }

    public function theLastLinkKeepsAnsweringAfterTheChainRunsOut(): void
    {
        $repository = Understudy::for(BookRepository::class);

        when(fn() => $repository->count())
            ->returns(1)
            ->then()->returns(2);

        Assert::same($repository->count(), 1);
        Assert::same($repository->count(), 2);
        Assert::same($repository->count(), 2);
        Assert::same($repository->count(), 2);
    }

    public function aVerbWithoutThenRefinesTheSameLink(): void
    {
        // Calling returns() twice describes one behaviour twice, not two
        // successive calls — that is what then() is for.
        $repository = Understudy::for(BookRepository::class);

        when(fn() => $repository->count())->returns(1)->returns(9);

        Assert::same($repository->count(), 9);
        Assert::same($repository->count(), 9);
    }

    public function severalValuesAreChainLinksNotACompetingSequence(): void
    {
        // returns($a, $b) is exactly returns($a)->then()->returns($b). Two
        // independent notions of "the next call" would step over $b: the chain
        // would advance past the link before its own sequence was exhausted.
        $repository = Understudy::for(BookRepository::class);

        when(fn() => $repository->count())
            ->returns(1, 2)
            ->then()->returns(9);

        Assert::same($repository->count(), 1);
        Assert::same($repository->count(), 2);
        Assert::same($repository->count(), 9);
        Assert::same($repository->count(), 9);
    }

    public function aNeverMethodWithAnExpectationButNoActionSaysWhichMistakeItIs(): void
    {
        // "you never said what this throws" reads very differently from
        // "nothing expected this call at all".
        $repository = Understudy::for(BookRepository::class);

        expect(fn() => $repository->abort('stop'));

        Expect::exception(NeverMethodCalled::class)->withMessage(
            "Understudy `BookRepository` expects `abort()`, but the method is declared `: never` and an expectation alone cannot answer it.\n"
            . 'Say what it throws: expect(fn () => $double->abort(...))->throws(new SomeException())',
        );

        $repository->abort('stop');
    }

    public function aChainMixesActionKinds(): void
    {
        $repository = Understudy::for(BookRepository::class);

        when(fn() => $repository->count())
            ->returns(1)
            ->then()->throws(new \DomainException('second'))
            ->then()->answers(static fn(): int => 3);

        Assert::same($repository->count(), 1);

        try {
            $repository->count();
            Assert::true(actual: false);
        } catch (\DomainException) {
            // Expected: the second link throws.
        }

        Assert::same($repository->count(), 3);
    }

    // --- expect() ------------------------------------------------------------

    #[ExpectNoAssertions]
    public function expectPassesWhenTheCallHappensOnce(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('Dune');

        expect(fn() => $repository->save($book));

        $repository->save($book);

        Understudy::verifyAll();
    }

    public function expectDefaultsToExactlyOnce(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('Dune');

        expect(fn() => $repository->save($book));

        $repository->save($book);
        $repository->save($book);

        Expect::exception(VerificationFailed::class)
            ->withMessageContaining('exactly 1 time')
            ->withMessageContaining('called 2 times');

        Understudy::verifyAll();
    }

    public function expectFailsWhenTheCallNeverHappens(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('Dune');

        expect(fn() => $repository->save($book));

        Expect::exception(VerificationFailed::class)->withMessageContaining('never');

        Understudy::verifyAll();
    }

    #[ExpectNoAssertions]
    public function expectAcceptsARange(): void
    {
        $repository = Understudy::for(BookRepository::class);

        expect(fn() => $repository->count())->times(1, 3);

        $repository->count();
        $repository->count();

        Understudy::verifyAll();
    }

    #[ExpectNoAssertions]
    public function expectAcceptsAnOpenRange(): void
    {
        $repository = Understudy::for(BookRepository::class);

        expect(fn() => $repository->count())->times(1, null);

        $repository->count();
        $repository->count();
        $repository->count();

        Understudy::verifyAll();
    }

    public function expectCanConfigureBehaviourToo(): void
    {
        $repository = Understudy::for(BookRepository::class);

        expect(fn() => $repository->count())->returns(7)->times(1);

        Assert::same($repository->count(), 7);

        Understudy::verifyAll();
    }

    public function anExpectationWithoutAnActionStillAnswersSafely(): void
    {
        // Counting and answering are separate concerns. `expect()` on a
        // non-void method is a complete statement about the count, and
        // demanding a returns() alongside it would be noise.
        $repository = Understudy::for(BookRepository::class);

        expect(fn() => $repository->count());

        Assert::same($repository->count(), 0);

        Understudy::verifyAll();
    }

    public function aMatchedExpectationSatisfiesAStrictDouble(): void
    {
        // The call was expected, so strictness has nothing left to complain
        // about — even though no action says what it answers.
        $repository = Understudy::for(BookRepository::class);
        Understudy::strict($repository);

        expect(fn() => $repository->count());

        Assert::same($repository->count(), 0);

        Understudy::verifyAll();
    }

    public function expectAndStubsAreCheckedTogether(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('Dune');

        expect(fn() => $repository->save($book));
        expect(fn() => $repository->count())->times(2);

        $repository->save($book);

        Expect::exception(VerificationFailed::class)->withMessageContaining('exactly 2 times');

        Understudy::verifyAll();
    }

    // --- times() on a stub ---------------------------------------------------

    #[ExpectNoAssertions]
    public function aPlainStubIsPermissionNotAClaim(): void
    {
        $repository = Understudy::for(BookRepository::class);

        when(fn() => $repository->count())->returns(1);

        Understudy::verifyAll();
    }

    public function timesTurnsAStubIntoAClaim(): void
    {
        $repository = Understudy::for(BookRepository::class);

        when(fn() => $repository->count())->returns(1)->times(2);

        $repository->count();

        Expect::exception(VerificationFailed::class)->withMessageContaining('exactly 2 times');

        Understudy::verifyAll();
    }

    // --- strict stubs --------------------------------------------------------

    public function strictStubsRejectAStubThatWasNeverUsed(): void
    {
        $repository = Understudy::for(BookRepository::class);

        when(fn() => $repository->find(Arg::any()))->returns(null);

        Expect::exception(VerificationFailed::class)->withMessageContaining('never used');

        Understudy::verifyAll(strictStubs: true);
    }

    #[ExpectNoAssertions]
    public function strictStubsAcceptAStubThatWasUsed(): void
    {
        $repository = Understudy::for(BookRepository::class);

        when(fn() => $repository->count())->returns(1);
        $repository->count();

        Understudy::verifyAll(strictStubs: true);
    }

    public function everyFailureIsReportedTogether(): void
    {
        $repository = Understudy::for(BookRepository::class);
        Understudy::label($repository, 'catalogue');

        expect(fn() => $repository->count())->times(2);
        expect(fn() => $repository->titles())->times(1);

        $error = null;

        try {
            Understudy::verifyAll();
        } catch (VerificationFailed $caught) {
            $error = $caught;
        }

        Assert::instanceOf($error, VerificationFailed::class);
        Assert::string($error->getMessage())->contains('count()');
        Assert::string($error->getMessage())->contains('titles()');
    }

    #[ExpectNoAssertions]
    public function verifyAllPassesWhenNothingWasDeclared(): void
    {
        Understudy::for(BookRepository::class);

        Understudy::verifyAll();
    }

    public function chainedReturnsAnswerInOrder(): void
    {
        $repository = Understudy::for(BookRepository::class);

        when(fn() => $repository->count())->returns(1, 2)->then()->returns(3, 4)->then()->returns(5);

        Assert::same(
            [
                $repository->count(),
                $repository->count(),
                $repository->count(),
                $repository->count(),
                $repository->count(),
            ],
            [1, 2, 3, 4, 5],
        );
    }
}
