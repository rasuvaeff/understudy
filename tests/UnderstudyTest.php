<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Exception\ForgottenDouble;
use Rasuvaeff\Understudy\Exception\InvalidCallSpecification;
use Rasuvaeff\Understudy\Exception\MatcherLeaked;
use Rasuvaeff\Understudy\Exception\NeverMethodCalled;
use Rasuvaeff\Understudy\Exception\StrictModeViolation;
use Rasuvaeff\Understudy\Exception\UnsupportedTarget;
use Rasuvaeff\Understudy\Exception\VerificationFailed;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Tests\Fixture\Book;
use Rasuvaeff\Understudy\Tests\Fixture\BookRepository;
use Rasuvaeff\Understudy\Tests\Fixture\Clock;
use Rasuvaeff\Understudy\Tests\Fixture\Named;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\MixedWriter;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\NarrowReturn;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\PrimaryNamed;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\SecondaryNamed;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\SlotsByRef;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\WideReturn;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\WriterInt;
use Rasuvaeff\Understudy\Tests\Fixture\VariadicSink;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Assert\ExpectNoAssertions;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(Understudy::class)]
final class UnderstudyTest
{
    #[AfterTest]
    public function tearDown(): void
    {
        Understudy::reset();
    }

    public function doubleSatisfiesTheContract(): void
    {
        Assert::instanceOf(Understudy::for(BookRepository::class), BookRepository::class);
    }

    public function doubleCombinesSeveralInterfaces(): void
    {
        $double = Understudy::for(BookRepository::class, Named::class);

        Assert::instanceOf($double, BookRepository::class);
        Assert::instanceOf($double, Named::class);
    }

    public function mixedAndNarrowParameterContractsProduceAUsableDouble(): void
    {
        $double = Understudy::for(MixedWriter::class, WriterInt::class);

        $double->write(1);
        $double->write('anything');

        Assert::same(count(Understudy::calls(fn() => $double->write(Arg::any()))), 2);
    }

    public function aStaticContractMemberFailsWithAnActionableError(): void
    {
        $double = Understudy::for(WriterInt::class);

        Expect::exception(InvalidCallSpecification::class)->withMessageContaining('Static method `describe()`');

        $double::describe();
    }

    public function aCovariantMultiTargetDoubleUsesTheNarrowestReturn(): void
    {
        $double = Understudy::for(WideReturn::class, NarrowReturn::class);
        $value = new \stdClass();

        when(fn() => $double->value())->returns($value);

        Assert::same($double->value(), $value);
    }

    #[ExpectNoAssertions]
    public function aMultiTargetDoubleKeepsThePrimaryParameterName(): void
    {
        $double = Understudy::for(PrimaryNamed::class, SecondaryNamed::class);

        $double->send(primary: 'message');
    }

    public function stubbedCallReturnsTheConfiguredValue(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('Dune');

        when(fn() => $repository->find(1))->returns($book);

        Assert::same($repository->find(1), $book);
    }

    public function stubMatchesOnArgumentsNotJustTheMethod(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $dune = new Book('Dune');

        when(fn() => $repository->find(1))->returns($dune);

        Assert::same($repository->find(1), $dune);
        Assert::null($repository->find(2));
    }

    public function laterStubWinsOverAnEarlierOne(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $first = new Book('first');
        $second = new Book('second');

        when(fn() => $repository->find(1))->returns($first);
        when(fn() => $repository->find(1))->returns($second);

        Assert::same($repository->find(1), $second);
    }

    public function earlierStubStaysReachableAsAFallback(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $any = new Book('any');
        $one = new Book('one');

        when(fn() => $repository->find(Arg::any()))->returns($any);
        when(fn() => $repository->find(1))->returns($one);

        Assert::same($repository->find(1), $one);
        Assert::same($repository->find(99), $any);
    }

    public function returnsWalksTheSequenceThenRepeatsTheLastValue(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $a = new Book('a');
        $b = new Book('b');

        when(fn() => $repository->find(1))->returns($a, $b);

        Assert::same($repository->find(1), $a);
        Assert::same($repository->find(1), $b);
        Assert::same($repository->find(1), $b);
    }

    public function throwsRaisesTheConfiguredError(): void
    {
        $repository = Understudy::for(BookRepository::class);

        when(fn() => $repository->find(1))->throws(new \RuntimeException('gone'));

        Expect::exception(\RuntimeException::class)->withMessage('gone');

        $repository->find(1);
    }

    public function answersComputesFromTheInvocation(): void
    {
        $repository = Understudy::for(BookRepository::class);

        when(fn() => $repository->find(Arg::any()))
            ->answers(static fn(Invocation $i): Book => new Book('book #' . $i->args[0]));

        Assert::same($repository->find(7)?->title, 'book #7');
    }

    public function looseDoubleAnswersWithTypeSafeDefaults(): void
    {
        $repository = Understudy::for(BookRepository::class);

        Assert::null($repository->find(1));
        Assert::same($repository->titles(), []);
        Assert::same($repository->count(), 0);
        Assert::same(iterator_to_array($repository->stream()), []);
    }

    public function looseDefaultForAUnionPrefersTheFirstSafeBranch(): void
    {
        $repository = Understudy::for(BookRepository::class);

        Assert::same($repository->describe(), '');
    }

    public function neverMethodThrowsInsteadOfReturning(): void
    {
        $repository = Understudy::for(BookRepository::class);

        Expect::exception(NeverMethodCalled::class)->withMessageContaining('abort()');

        $repository->abort('stop');
    }

    public function neverMethodStillHonoursAConfiguredThrow(): void
    {
        $repository = Understudy::for(BookRepository::class);

        when(fn() => $repository->abort('stop'))->throws(new \DomainException('configured'));

        Expect::exception(\DomainException::class);

        $repository->abort('stop');
    }

    public function strictDoubleRejectsAnUnexpectedCall(): void
    {
        $repository = Understudy::for(BookRepository::class);
        Understudy::strict($repository);

        Expect::exception(StrictModeViolation::class)->withMessageContaining('count()');

        $repository->count();
    }

    public function strictDoubleStillAnswersConfiguredCalls(): void
    {
        $repository = Understudy::for(BookRepository::class);
        Understudy::strict($repository);

        when(fn() => $repository->count())->returns(3);

        Assert::same($repository->count(), 3);
    }

    #[ExpectNoAssertions]
    public function verifyPassesWhenTheCallHappened(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('Dune');

        $repository->save($book);

        verify(fn() => $repository->save($book));
    }

    #[ExpectNoAssertions]
    public function verifyCountsExactly(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('Dune');

        $repository->save($book);
        $repository->save($book);

        verify(fn() => $repository->save($book), times: 2);
    }

    public function verifyReportsTheMismatchedCount(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('Dune');

        $repository->save($book);

        Expect::exception(VerificationFailed::class)
            ->withMessageContaining('exactly 3 times')
            ->withMessageContaining('called 1 time');

        Understudy::verify(fn() => $repository->save($book), times: 3);
    }

    public function verifyMarksTheArgumentThatDiffered(): void
    {
        $repository = Understudy::for(BookRepository::class);

        $repository->tag('beta', 2);

        Expect::exception(VerificationFailed::class)->withMessageContaining("*'beta'*");

        Understudy::verify(fn() => $repository->tag('alpha', 2));
    }

    #[ExpectNoAssertions]
    public function verifyNeverPassesWhenNothingHappened(): void
    {
        $repository = Understudy::for(BookRepository::class);

        verify(fn() => $repository->count(), never: true);
    }

    public function verifyNeverFailsWhenTheCallHappened(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $repository->count();

        Expect::exception(VerificationFailed::class)->withMessageContaining('never');

        Understudy::verify(fn() => $repository->count(), never: true);
    }

    #[ExpectNoAssertions]
    public function verifyAcceptsAMinimumWithoutAnUpperBound(): void
    {
        $repository = Understudy::for(BookRepository::class);

        $repository->count();
        $repository->count();
        $repository->count();

        verify(fn() => $repository->count(), minimum: 2);
    }

    public function verifyReportsAnUnmetMinimum(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $repository->count();

        Expect::exception(VerificationFailed::class)->withMessageContaining('at least 2 times');

        Understudy::verify(fn() => $repository->count(), minimum: 2);
    }

    public function callsExposesTheRecordedInvocations(): void
    {
        $repository = Understudy::for(BookRepository::class);

        when(fn() => $repository->find(1))->returns(new Book('Dune'));
        $repository->find(1);
        $repository->find(1);

        $calls = Understudy::calls(fn() => $repository->find(1));

        Assert::same(count($calls), 2);
        Assert::true($calls[0]->didReturn());
        Assert::same($calls[0]->returned()?->title, 'Dune');
    }

    public function invocationRecordsAThrownOutcome(): void
    {
        $repository = Understudy::for(BookRepository::class);

        when(fn() => $repository->find(1))->throws(new \RuntimeException('gone'));

        try {
            $repository->find(1);
        } catch (\RuntimeException) {
            // Swallowed on purpose: the recorded outcome is what this asserts.
        }

        $calls = Understudy::calls(fn() => $repository->find(1));

        Assert::true($calls[0]->didThrow());
        Assert::instanceOf($calls[0]->thrown(), \RuntimeException::class);
    }

    #[ExpectNoAssertions]
    public function unusedPassesForAnUntouchedDouble(): void
    {
        Understudy::unused(Understudy::for(BookRepository::class));
    }

    public function unusedReportsWhatWasCalled(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $repository->tag('alpha');

        Expect::exception(VerificationFailed::class)->withMessageContaining("tag('alpha', 1)");

        Understudy::unused($repository);
    }

    public function labelNamesTheDoubleInFailures(): void
    {
        $repository = Understudy::for(BookRepository::class);
        Understudy::label($repository, 'primary catalogue');

        Expect::exception(VerificationFailed::class)->withMessageContaining('primary catalogue');

        Understudy::verify(fn() => $repository->count());
    }

    public function failureNamesTheContractWhenNoLabelWasSet(): void
    {
        $repository = Understudy::for(BookRepository::class);

        Expect::exception(VerificationFailed::class)->withMessageContaining('BookRepository');

        Understudy::verify(fn() => $repository->count());
    }

    public function specificationClosureWithoutACallIsRejected(): void
    {
        Expect::exception(InvalidCallSpecification::class)->withMessageContaining('exactly one direct call');

        Understudy::when(static fn(): bool => true);
    }

    public function specificationClosureFailureKeepsTheOriginalCause(): void
    {
        Expect::exception(InvalidCallSpecification::class)->withPrevious(\DomainException::class);

        Understudy::when(static function (): never {
            throw new \DomainException('closure blew up');
        });
    }

    public function classTargetsAreRejectedWithAnActionableMessage(): void
    {
        Expect::exception(UnsupportedTarget::class)->withMessageContaining('interface');

        Understudy::for(Book::class);
    }

    public function missingTargetIsRejected(): void
    {
        Expect::exception(UnsupportedTarget::class)->withMessageContaining('no such class or interface');

        // Deliberately not a real class: psalm covers src/ only, so the
        // invalid argument raises no analysis error here.
        Understudy::for('Nope\\NotHere');
    }

    public function resetForgetsEverything(): void
    {
        $repository = Understudy::for(BookRepository::class);
        when(fn() => $repository->count())->returns(5);
        Understudy::reset();

        $fresh = Understudy::for(BookRepository::class);

        Assert::same($fresh->count(), 0);
    }

    public function returnsPreservesAConfiguredNull(): void
    {
        // `??` cannot tell a configured null from a missing entry, and would
        // skip straight to the last value.
        $repository = Understudy::for(BookRepository::class);
        $book = new Book('second');

        when(fn() => $repository->find(1))->returns(null, $book);

        Assert::null($repository->find(1));
        Assert::same($repository->find(1), $book);
    }

    public function aByReferenceMethodDispatchesWithoutANotice(): void
    {
        // PHP can only bind a reference to a variable, so the generated body
        // must assign the dispatch result before returning it.
        $registry = Understudy::for(SlotsByRef::class);

        when(fn() => $registry->slots())->returns(['a' => 1]);

        Assert::same($registry->slots(), ['a' => 1]);
    }

    public function aNeverMethodConfiguredToReturnIsRejected(): void
    {
        // Returning from a `: never` method is a TypeError by language rule;
        // the message has to name the real mistake instead.
        $repository = Understudy::for(BookRepository::class);

        when(fn() => $repository->abort('stop'))->returns('nope');

        Expect::exception(NeverMethodCalled::class)->withMessageContaining('cannot');

        $repository->abort('stop');
    }

    public function aDoubleUsedAfterResetSaysSo(): void
    {
        // Answering with null would violate the declared return type and
        // surface far from the actual mistake.
        $repository = Understudy::for(BookRepository::class);
        Understudy::reset();

        Expect::exception(ForgottenDouble::class)->withMessageContaining('count()');

        $repository->count();
    }

    public function aMatcherFromAnotherContextStillRecords(): void
    {
        // Recording belongs to the caller: a double created before the
        // specification closure runs must still signal, not log a real call.
        $repository = Understudy::for(BookRepository::class);
        $repository->count();

        when(fn() => $repository->count())->returns(9);

        Assert::same($repository->count(), 9);
        Assert::same(count(Understudy::calls(fn() => $repository->count())), 2);
    }

    public function matchersSelectTheStubByArgumentShape(): void
    {
        $repository = Understudy::for(BookRepository::class);

        // Broad first, specific second: the most recently registered stub is
        // tried first, so a catch-all registered last would shadow everything.
        when(fn() => $repository->tag(Arg::any(), Arg::any()))->returns('anything');
        when(fn() => $repository->tag(Arg::string(matches: '/^ord-/'), Arg::int(min: 5)))->returns('big order');

        Assert::same($repository->tag('ord-1', 9), 'big order');
        Assert::same($repository->tag('ord-1', 1), 'anything');
        Assert::same($repository->tag('inv-1', 9), 'anything');
    }

    public function remainingMatchesAVariadicTailOfAnyLength(): void
    {
        $sink = Understudy::for(VariadicSink::class);

        when(fn() => $sink->write('a', Arg::remaining()))->returns(true);

        Assert::true($sink->write('a'));
        Assert::true($sink->write('a', 1));
        Assert::true($sink->write('a', 1, 2, 3));
        Assert::false($sink->write('b', 1));
    }

    public function noneRequiresAnEmptyVariadicTail(): void
    {
        $sink = Understudy::for(VariadicSink::class);

        when(fn() => $sink->write('a', Arg::none()))->returns(true);

        Assert::true($sink->write('a'));
        Assert::false($sink->write('a', 1));
    }

    public function aTailMatcherOutsideTheLastSlotIsRejected(): void
    {
        // Left to matching, a misplaced remaining() behaves as a silent
        // wildcard for that one argument — worse than any error message.
        $sink = Understudy::for(VariadicSink::class);

        Expect::exception(InvalidCallSpecification::class)
            ->withMessageContaining('remaining()')
            ->withMessageContaining('argument #1')
            ->withMessageContaining('write()');

        Understudy::when(fn() => $sink->write(Arg::remaining(), 1));
    }

    public function aMisplacedEmptyTailIsRejectedToo(): void
    {
        $sink = Understudy::for(VariadicSink::class);

        Expect::exception(InvalidCallSpecification::class)->withMessageContaining('none()');

        Understudy::when(fn() => $sink->write(Arg::none(), 1));
    }

    public function aTailMatcherInTheLastSlotIsAccepted(): void
    {
        $sink = Understudy::for(VariadicSink::class);

        when(fn() => $sink->write('a', Arg::remaining()))->returns(true);

        Assert::true($sink->write('a', 1, 2));
    }

    public function verifyRejectsAMisplacedTailMatcherAsWell(): void
    {
        $sink = Understudy::for(VariadicSink::class);

        Expect::exception(InvalidCallSpecification::class);

        Understudy::verify(fn() => $sink->write(Arg::remaining(), 1));
    }

    public function aMatcherReachingARealCallIsRejected(): void
    {
        // Matchers are protocol, not values: the code under test must never
        // receive one.
        $repository = Understudy::for(BookRepository::class);

        Expect::exception(MatcherLeaked::class)
            ->withMessageContaining('find()')
            ->withMessageContaining('any()');

        $repository->find(Arg::any());
    }

    #[ExpectNoAssertions]
    public function verifyAcceptsMatchersToo(): void
    {
        $repository = Understudy::for(BookRepository::class);

        $repository->tag('ord-1', 9);
        $repository->tag('ord-2', 3);

        verify(fn() => $repository->tag(Arg::string(matches: '/^ord-/'), Arg::any()), times: 2);
        verify(fn() => $repository->tag(Arg::any(), Arg::int(min: 5)), times: 1);
    }

    public function aFailureMessageDescribesTheMatcher(): void
    {
        $repository = Understudy::for(BookRepository::class);

        Expect::exception(VerificationFailed::class)->withMessageContaining("tag(string(matches: /^ord-/), int(min: 5))");

        Understudy::verify(fn() => $repository->tag(Arg::string(matches: '/^ord-/'), Arg::int(min: 5)));
    }

    public function doublesOfTheSameContractKeepSeparateState(): void
    {
        $first = Understudy::for(Clock::class);
        $second = Understudy::for(Clock::class);

        when(fn() => $first->now())->returns(100);

        Assert::same($first->now(), 100);
        Assert::same($second->now(), 0);
    }
}
