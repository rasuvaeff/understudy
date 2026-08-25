<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Codegen\Blueprint;
use Rasuvaeff\Understudy\Codegen\DoubleFactory;
use Rasuvaeff\Understudy\Codegen\MethodSignature;
use Rasuvaeff\Understudy\Codegen\TargetUnifier;
use Rasuvaeff\Understudy\Codegen\TypeRenderer;
use Rasuvaeff\Understudy\Exception\ForgottenDouble;
use Rasuvaeff\Understudy\Exception\InvalidCallSpecification;
use Rasuvaeff\Understudy\Exception\MatcherLeaked;
use Rasuvaeff\Understudy\Exception\NeverMethodCalled;
use Rasuvaeff\Understudy\Exception\StrictModeViolation;
use Rasuvaeff\Understudy\Exception\UnsupportedTarget;
use Rasuvaeff\Understudy\Exception\VerificationFailed;
use Rasuvaeff\Understudy\Expectation\Expectation;
use Rasuvaeff\Understudy\FailureReport;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Outcome;
use Rasuvaeff\Understudy\Runtime\DoubleState;
use Rasuvaeff\Understudy\Runtime\InvocationSignal;
use Rasuvaeff\Understudy\Runtime\Mode;
use Rasuvaeff\Understudy\Runtime\Runtime;
use Rasuvaeff\Understudy\Runtime\RuntimeContext;
use Rasuvaeff\Understudy\Tests\Fixture\Book;
use Rasuvaeff\Understudy\Tests\Fixture\BookRepository;
use Rasuvaeff\Understudy\Tests\Fixture\Clock;
use Rasuvaeff\Understudy\Tests\Fixture\HashedContract;
use Rasuvaeff\Understudy\Tests\Fixture\HashedContractToo;
use Rasuvaeff\Understudy\Tests\Fixture\Named;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\IntersectedPair;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\IntersectionAlpha;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\IntersectionBeta;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\MixedWriter;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\NarrowReturn;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\PrimaryNamed;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\SecondaryNamed;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\SelfReturn;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\SlotsByRef;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\StaticReturn;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\WideReturn;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\WriterInt;
use Rasuvaeff\Understudy\Tests\Fixture\VariadicSink;
use Rasuvaeff\Understudy\Tests\Support\GoldenMessage;
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

#[Test]
#[Covers(Understudy::class)]
#[Covers(Runtime::class)]
#[Covers(RuntimeContext::class)]
#[Covers(DoubleState::class)]
#[Covers(InvocationSignal::class)]
#[Covers(Mode::class)]
#[Covers(Invocation::class)]
#[Covers(Outcome::class)]
#[Covers(FailureReport::class)]
#[Covers(Expectation::class)]
#[Covers(DoubleFactory::class)]
#[Covers(Blueprint::class)]
#[Covers(MethodSignature::class)]
#[Covers(ForgottenDouble::class)]
#[Covers(InvalidCallSpecification::class)]
#[Covers(MatcherLeaked::class)]
#[Covers(NeverMethodCalled::class)]
#[Covers(StrictModeViolation::class)]
#[Covers(UnsupportedTarget::class)]
#[Covers(VerificationFailed::class)]
#[Covers(TargetUnifier::class)]
#[Covers(TypeRenderer::class)]
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

    public function freshContextIsIdle(): void
    {
        Assert::true(Understudy::idle());
    }

    public function creatingADoubleEndsIdlenessUntilReset(): void
    {
        Understudy::for(BookRepository::class);

        Assert::false(Understudy::idle());

        Understudy::reset();

        Assert::true(Understudy::idle());
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

        Expect::exception(InvalidCallSpecification::class)->withMessage(
            "Static method `describe()` cannot be called on an understudy because static calls have no instance state.\n"
            . 'Inject an instance dependency and double that contract instead.',
        );

        $double::describe();
    }

    public function aCovariantMultiTargetDoubleUsesTheNarrowestReturn(): void
    {
        $double = Understudy::for(WideReturn::class, NarrowReturn::class);
        $value = new \stdClass();

        when(fn() => $double->value())->returns($value);

        Assert::same($double->value(), $value);
    }

    public function aSelfAndStaticReturnCanReturnTheGeneratedDouble(): void
    {
        $double = Understudy::for(SelfReturn::class, StaticReturn::class);

        when(fn() => $double->copy())->returns($double);

        Assert::same($double->copy(), $double);
    }

    public function unrelatedInterfaceReturnsCanReturnTheirIntersection(): void
    {
        $double = Understudy::for(IntersectionAlpha::class, IntersectionBeta::class);

        when(fn() => $double->intersected())->returns($double);

        Assert::same($double->intersected(), $double);
        Assert::null($double->nullableIntersection());
    }

    public function aLooseIntersectionReturnBecomesOneDoubleOfBothContracts(): void
    {
        $double = Understudy::for(IntersectedPair::class);
        $value = $double->pick();

        Assert::instanceOf($value, IntersectionAlpha::class);
        Assert::instanceOf($value, IntersectionBeta::class);
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

        Expect::exception(NeverMethodCalled::class)->withMessage(
            "Understudy `BookRepository` received a call to `abort()`, which is declared `: never` and cannot return.\n"
            . 'Configure what it throws: when(fn () => $double->abort(...))->throws(new SomeException())',
        );

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

        Expect::exception(StrictModeViolation::class)->withMessage(
            "Understudy `BookRepository` is strict and received an unexpected call to `count()`.\n"
            . 'Configure it first: when(fn () => $double->count(...))->returns(...)',
        );

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
        Expect::exception(InvalidCallSpecification::class)->withMessage(
            'The specification closure did not call a method on an understudy. '
            . 'It must contain exactly one direct call, for example: '
            . 'when(fn () => $repository->find(123))',
        );

        Understudy::when(static fn(): bool => true);
    }

    public function specificationClosureFailureKeepsTheOriginalCause(): void
    {
        Expect::exception(InvalidCallSpecification::class)
            ->withPrevious(\DomainException::class)
            ->withMessage('The specification closure threw before it reached an understudy: closure blew up');

        Understudy::when(static function (): never {
            throw new \DomainException('closure blew up');
        });
    }

    public function aFinalClassTargetIsRejectedWithAnActionableMessage(): void
    {
        Expect::exception(UnsupportedTarget::class)->withMessage(
            'Cannot create an understudy for `' . Book::class . "`: the class is final, and bypass is not enabled.\n"
            . "- Preferred: if it implements an interface, double the interface.\n"
            . "- If it is a value object, prefer a real instance.\n"
            . "- If it is a concrete dependency you cannot change, enable bypass before the class is\n"
            . "  first loaded: Understudy::bypassFinals(Book::class)\n"
            . '- Introducing an interface remains the cleanest long-term fix.',
        );

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

        $notices = [];
        set_error_handler(static function (int $severity, string $message) use (&$notices): bool {
            $notices[] = $message;

            return true;
        });

        try {
            $value = $registry->slots();
        } finally {
            restore_error_handler();
        }

        Assert::same($value, ['a' => 1]);
        // Returning the dispatch expression directly raises "Only variable
        // references should be returned by reference".
        Assert::same($notices, []);
    }

    public function aNeverMethodConfiguredToReturnIsRejected(): void
    {
        // Returning from a `: never` method is a TypeError by language rule;
        // the message has to name the real mistake instead.
        $repository = Understudy::for(BookRepository::class);

        when(fn() => $repository->abort('stop'))->returns('nope');

        Expect::exception(NeverMethodCalled::class)->withMessage(
            "Understudy `BookRepository` has `abort()` configured to return, but the method is declared `: never` and cannot.\n"
            . 'Configure it to throw instead: when(fn () => $double->abort(...))->throws(new SomeException())',
        );

        $repository->abort('stop');
    }

    public function aDoubleUsedAfterResetSaysSo(): void
    {
        // Answering with null would violate the declared return type and
        // surface far from the actual mistake.
        $repository = Understudy::for(BookRepository::class);
        Understudy::reset();

        Expect::exception(ForgottenDouble::class)->withMessage(
            "This understudy is no longer known to Understudy, but `count()` was called on it.\n"
            . 'It was created before a reset(); create doubles inside the test that uses them '
            . 'rather than sharing one across tests.',
        );

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

        Expect::exception(InvalidCallSpecification::class)->withMessage(
            '`remaining()` stands for the whole variadic tail, so it may only be the last argument, '
            . "but it was given as argument #1 of `write()`.\n"
            . 'Move it to the end, or use Arg::any() to match that one argument.',
        );

        Understudy::when(fn() => $sink->write(Arg::remaining(), 1));
    }

    public function aMisplacedEmptyTailIsRejectedToo(): void
    {
        $sink = Understudy::for(VariadicSink::class);

        Expect::exception(InvalidCallSpecification::class)->withMessage(
            '`none()` stands for the whole variadic tail, so it may only be the last argument, '
            . "but it was given as argument #1 of `write()`.\n"
            . 'Move it to the end, or use Arg::any() to match that one argument.',
        );

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

        Expect::exception(MatcherLeaked::class)->withMessage(
            "Argument #1 of `find()` was given the matcher `any()` during a real call.\n"
            . 'Matchers belong inside when()/verify()/calls(), not in the call the code under test makes.',
        );

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

    // --- verify() failure reports --------------------------------------------

    public function aMethodThatWasNeverCalledSaysExactlyThat(): void
    {
        $repository = Understudy::for(BookRepository::class);

        // Another method was called, so there is a log — but none of it is
        // about `count()`, and listing it would be noise.
        $repository->titles();

        Expect::exception(VerificationFailed::class)->withMessage(
            'Understudy `BookRepository` expected `count()` to be called at least 1 time, but it was never called.',
        );

        verify(fn() => $repository->count());
    }

    public function callsToTheSameMethodAreListedWithTheDifferingArgumentMarked(): void
    {
        $repository = Understudy::for(BookRepository::class);

        $repository->tag('alpha');
        $repository->tag('beta');

        Expect::exception(VerificationFailed::class)->withMessage(
            GoldenMessage::read('verify-lists-same-method-calls-with-marked-argument'),
        );

        verify(fn() => $repository->tag('gamma'));
    }

    public function aPlainCountMismatchNeedsNoCallLog(): void
    {
        // Every call to the method matched: repeating them under "these are
        // the calls" would tell the reader nothing new.
        $repository = Understudy::for(BookRepository::class);

        $repository->count();
        $repository->count();

        Expect::exception(VerificationFailed::class)->withMessage(
            'Understudy `BookRepository` expected `count()` to be called exactly 3 times, but it was called 2 times.',
        );

        verify(fn() => $repository->count(), times: 3);
    }

    public function aRangeIsSpelledOutAsARange(): void
    {
        $repository = Understudy::for(BookRepository::class);

        $repository->count();
        $repository->count();
        $repository->count();

        Expect::exception(VerificationFailed::class)->withMessage(
            'Understudy `BookRepository` expected `count()` to be called between 0 and 2 times, but it was called 3 times.',
        );

        verify(fn() => $repository->count(), minimum: 0, maximum: 2);
    }

    public function anAtLeastFailureNamesTheLowerBound(): void
    {
        $repository = Understudy::for(BookRepository::class);

        Expect::exception(VerificationFailed::class)->withMessage(
            'Understudy `BookRepository` expected `count()` to be called at least 2 times, but it was never called.',
        );

        verify(fn() => $repository->count(), minimum: 2);
    }

    public function averificationThatWantedNothingSaysNever(): void
    {
        $repository = Understudy::for(BookRepository::class);

        $repository->count();

        Expect::exception(VerificationFailed::class)->withMessage(
            'Understudy `BookRepository` expected `count()` to be called never, but it was called 1 time.',
        );

        verify(fn() => $repository->count(), never: true);
    }

    public function theGeneratedClassIsNamedAfterAHashOfItsContracts(): void
    {
        // The suffix is a fixed-width digest: a shorter or longer slice would
        // change how likely two contract sets are to collide.
        // A contract no other test doubles: otherwise the blueprint cache
        // answers and the naming code never runs here.
        $double = Understudy::for(HashedContract::class);

        Assert::true((bool) preg_match(
            '/^Rasuvaeff\\\\Understudy\\\\Codegen\\\\Generated\\\\Understudy_[0-9a-f]{16}$/',
            $double::class,
        ));
    }

    public function twoContractSetsGetTwoGeneratedClasses(): void
    {
        Assert::true(
            Understudy::for(HashedContract::class)::class !== Understudy::for(HashedContractToo::class)::class,
        );
    }

    public function configuringAForgottenDoubleIsRejected(): void
    {
        $repository = Understudy::for(BookRepository::class);
        Understudy::reset();

        Expect::exception(InvalidCallSpecification::class)->withMessage(
            'The specification closure did not call a method on an understudy. '
            . 'It must contain exactly one direct call, for example: '
            . 'when(fn () => $repository->find(123))',
        );

        Understudy::label($repository, 'catalogue');
    }

    public function anExpectationWithoutAnActionStopsTheSearch(): void
    {
        // The expectation matched first and has no action of its own, so the
        // mode's default answers; the stub behind it is not consulted.
        $repository = Understudy::for(BookRepository::class);

        when(fn() => $repository->tag(Arg::any()))->returns('stub');
        expect(fn() => $repository->tag('alpha'));

        Assert::same($repository->tag('alpha'), '');

        Understudy::verifyAll();
    }
}
