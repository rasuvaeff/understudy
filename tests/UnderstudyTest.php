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
use Rasuvaeff\Understudy\Exception\InvalidSpecificationArgument;
use Rasuvaeff\Understudy\Exception\MatcherLeaked;
use Rasuvaeff\Understudy\Exception\NeverMethodCalled;
use Rasuvaeff\Understudy\Exception\StrictModeViolation;
use Rasuvaeff\Understudy\Exception\UnsupportedTarget;
use Rasuvaeff\Understudy\Exception\VerificationFailed;
use Rasuvaeff\Understudy\Expectation\Expectation;
use Rasuvaeff\Understudy\Expectation\ReturnContract;
use Rasuvaeff\Understudy\ExpectBuilder;
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
use Rasuvaeff\Understudy\Tests\Fixture\Librarian;
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
use Rasuvaeff\Understudy\WhenBuilder;
use Testo\Assert;
use Testo\Assert\ExpectNoAssertions;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

use function Rasuvaeff\Understudy\expect;
use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(Understudy::class)]
#[Covers(WhenBuilder::class)]
#[Covers(ExpectBuilder::class)]
#[Covers(ReturnContract::class)]
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

    /**
     * The mode reads as "a strict double of this", so it has to be usable as
     * an expression: a container definition built from
     * `Understudy::strict(Understudy::for(X::class))` used to store `null` and
     * fail three steps away from the cause.
     */
    public function strictAndLabelAnswerWithTheDoubleTheyConfigured(): void
    {
        $repository = Understudy::for(BookRepository::class);

        Assert::true(Understudy::strict($repository) === $repository);
        Assert::true(Understudy::label($repository, 'primary') === $repository);
    }

    public function strictRefusalShowsTheCallAndWhatDidNotAcceptIt(): void
    {
        // Naming only the method sent the reader back to a test that did
        // configure `save` — what differed was the argument, and the message
        // did not carry it.
        $repository = Understudy::for(BookRepository::class);

        when(fn() => $repository->save(new Book('Herbert')))->returns(null);
        Understudy::strict($repository);

        Expect::exception(StrictModeViolation::class)->withMessage(
            GoldenMessage::read('strict-refusal-lists-what-did-not-accept-the-call'),
        );

        $repository->save(new Book('Dune'));
    }

    public function strictRefusalMarksEachArgumentThatRejectedTheCall(): void
    {
        $repository = Understudy::for(BookRepository::class);

        when(fn() => $repository->tag(Arg::string(matches: '/^a/'), 2))->returns('x');
        Understudy::strict($repository);

        Expect::exception(StrictModeViolation::class)->withMessage(
            GoldenMessage::read('strict-refusal-marks-both-rejecting-arguments'),
        );

        $repository->tag('beta');
    }

    public function strictRefusalMarksAPositionTheCallNeverCarried(): void
    {
        // The stub declares two arguments and the call carried one: nothing
        // accepts an argument that was not there.
        $repository = Understudy::for(BookRepository::class);

        when(fn() => $repository->tag('beta', 2))->returns('x');
        Understudy::strict($repository);

        Expect::exception(StrictModeViolation::class)->withMessageContaining("tag('beta', *2*)");

        $repository->tag('beta');
    }

    public function aMatcherThatBreaksWhileRenderingCountsAsOneThatDidNotAccept(): void
    {
        // Rendering happens during dispatch, inside the code under test. A
        // predicate that throws here would replace the refusal with its own
        // exception, and the reader would never learn what was refused —
        // worse, the predicate never ran during matching at all, because the
        // first argument had already failed and matching stops there.
        $repository = Understudy::for(BookRepository::class);

        when(fn() => $repository->tag(
            'alpha',
            Arg::satisfies(static fn(mixed $v): bool => throw new \LogicException('boom'), 'explodes'),
        ))->returns('x');
        Understudy::strict($repository);

        Expect::exception(StrictModeViolation::class)->withMessageContaining("tag(*'alpha'*, *explodes*)");

        $repository->tag('beta');
    }

    public function aLongListOfCandidatesIsCutWithACount(): void
    {
        // A wall of stubs tells the reader less than a count does.
        $repository = Understudy::for(BookRepository::class);

        foreach (range(1, 7) as $weight) {
            when(fn() => $repository->tag('alpha', $weight))->returns('x');
        }

        Understudy::strict($repository);

        Expect::exception(StrictModeViolation::class)->withMessageContaining('… and 2 more');

        $repository->tag('beta');
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

    /**
     * The arguments of `find()` are evaluated before `find()` is dispatched,
     * so the recording used to see `count()` alone: the closure was abandoned
     * on that first signal, `count()` was stubbed with the `returns()` meant
     * for `find()`, and `find()` was never specified at all.
     */
    public function specificationClosureWithANestedDoubleCallIsRejected(): void
    {
        $repository = Understudy::for(BookRepository::class);

        Expect::exception(InvalidCallSpecification::class)->withMessage(
            'The specification closure calls `count()` on understudy `BookRepository` and then `find()` on '
            . 'understudy `BookRepository`. A closure must contain exactly one direct call on a double; with two, '
            . 'one of them would be specified silently and the other not at all. Stub each call in a when() of its '
            . 'own, and where one call feeds the arguments of another pass a literal or a matcher instead, for '
            . 'example: when(fn () => $repository->find(Arg::any()))',
        );

        Understudy::when(fn() => $repository->find($repository->count()));
    }

    /**
     * Across two doubles the inner call belonged to the other one: the stub
     * landed on the wrong object with a value its return type could not hold.
     */
    public function aNestedCallOnAnotherDoubleIsRejectedByBothNames(): void
    {
        $repository = Understudy::label(Understudy::for(BookRepository::class), 'outer');
        $counter = Understudy::label(Understudy::for(BookRepository::class), 'inner');

        Expect::exception(InvalidCallSpecification::class)
            ->withMessageContaining('calls `count()` on understudy `inner` and then `find()` on understudy `outer`');

        Understudy::expect(fn() => $repository->find($counter->count()));
    }

    /**
     * Two calls side by side are as ambiguous as two nested ones, and the
     * recording sees them in dispatch order.
     */
    public function twoCallsInARowAreRejected(): void
    {
        $repository = Understudy::for(BookRepository::class);

        Expect::exception(InvalidCallSpecification::class)
            ->withMessageContaining('calls `count()` on understudy `BookRepository` and then `describe()` on understudy `BookRepository`');

        Understudy::when(fn() => $repository->count() . $repository->describe());
    }

    /**
     * A `: never` method has no default to answer with, so its signal ends the
     * closure — and the specification stands, as does one for a method that
     * does have a default.
     */
    public function recordingToleratesAMethodWithoutADefault(): void
    {
        $repository = Understudy::for(BookRepository::class);

        Understudy::when(fn() => $repository->abort('x'))->throws(new \RuntimeException('aborted'));
        Understudy::when(fn() => $repository->find(1))->returns(new Book('one'));

        Assert::same(count(Understudy::calls(fn() => $repository->find(Arg::any()))), 0);
        Assert::same(count(Understudy::calls(fn() => $repository->count())), 0);
        Assert::same($repository->find(1)?->title, 'one');
        Expect::exception(\RuntimeException::class)->withMessage('aborted');
        $repository->abort('x');
    }

    /**
     * The closure is user code, and the second pass that used to look past
     * the first call ran all of it again: a counter went up twice, a factory
     * built twice. (#142)
     */
    public function specificationClosureRunsOnlyOnce(): void
    {
        $repository = Understudy::for(BookRepository::class);
        $runs = 0;

        Understudy::when(function () use ($repository, &$runs): ?Book {
            ++$runs;

            return $repository->find($runs);
        })->returns(new Book('one'));

        Assert::same($runs, 1);
        Assert::same($repository->find(1)?->title, 'one');
    }

    /**
     * A double created inside the closure used to be created twice, and the
     * second one — stubbed by nobody, called by nobody — stayed in the
     * context, where `strictStubs` reported its stub as never used. (#142)
     */
    public function specificationClosureDoesNotRegisterASecondDouble(): void
    {
        $created = [];

        Understudy::when(function () use (&$created): int {
            $repository = Understudy::for(BookRepository::class);
            $created[] = $repository;

            return $repository->count();
        })->returns(1);

        Assert::same(count($created), 1);
        Assert::false(Understudy::idle());
        Assert::same($created[0]->count(), 1);
        Understudy::verifyAll(strictStubs: true);
    }

    /**
     * Code after the call runs against the default the call was answered
     * with, and may not survive it — a call on the `null` a `?Book` answers
     * with, a closure return type the default does not fit. No
     * recording ever ran that code before, and it is not the specification.
     */
    #[DataProvider('codeAfterTheCallProvider')]
    public function codeAfterTheCallThatFailsOnTheDefaultDoesNotFailTheSpecification(\Closure $specification): void
    {
        $repository = Understudy::for(BookRepository::class);

        Understudy::when($specification($repository))->returns(new Book('one'));

        Assert::same($repository->find(1)?->title, 'one');
    }

    public static function codeAfterTheCallProvider(): iterable
    {
        yield 'method call on null' => [fn(BookRepository $r): \Closure => fn() => $r->find(1)->jsonSerialize()];
        yield 'closure return type' => [fn(BookRepository $r): \Closure => fn(): Book => $r->find(1)];
        yield 'explicit throw after the call' => [fn(BookRepository $r): \Closure => function () use ($r): void {
            $r->find(1);

            throw new \LogicException('after the call');
        }];
    }

    /**
     * Two doubles of one contract used to be both `BookRepository` in a
     * report, and "which one?" was the reader's problem. The first keeps the
     * bare name — one double per contract is the common shape and nothing
     * changes for it — the second and later are numbered, and an explicit
     * label still outranks the number.
     */
    public function laterDoublesOfTheSameContractGetNumberedDefaultLabels(): void
    {
        $first = Understudy::for(BookRepository::class);
        $second = Understudy::for(BookRepository::class);
        $third = Understudy::label(Understudy::for(BookRepository::class), 'archive');
        $other = Understudy::for(Librarian::class);

        expect(fn() => $first->count());
        expect(fn() => $second->count());
        expect(fn() => $third->count());
        expect(fn() => $other->pick());

        try {
            Understudy::verifyAll();
            Assert::true(actual: false, message: 'Expected VerificationFailed');
        } catch (VerificationFailed $failure) {
            Assert::string($failure->getMessage())
                ->contains('Understudy `BookRepository` expected `count()`')
                ->contains('Understudy `BookRepository#2` expected `count()`')
                ->contains('Understudy `archive` expected `count()`')
                ->contains('Understudy `Librarian` expected `pick()`');
        }
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

    /**
     * The library's own refusals are already about the specification — a
     * matcher built with an impossible range, a captor inside a combinator, a
     * double the test retired. Wrapping one in "the closure threw before it
     * reached an understudy" buried the sentence that says what to change.
     */
    public function anUnderstudyErrorRaisedInsideTheClosureIsNotRewrapped(): void
    {
        $repository = Understudy::for(BookRepository::class);

        try {
            Understudy::when(static fn(): ?Book => $repository->find(Arg::int(min: 5, max: 1)));
        } catch (InvalidSpecificationArgument $refusal) {
            Assert::string($refusal->getMessage())->contains('describes an empty range');
            Assert::false(str_contains($refusal->getMessage(), 'threw before it reached an understudy'));

            return;
        }

        Assert::fail('the impossible range was not refused');
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
        // the message has to name the real mistake instead. `returns()` is
        // refused where it is written; `answers()` can only be judged by what
        // it produces, at the call.
        $repository = Understudy::for(BookRepository::class);

        when(fn() => $repository->abort('stop'))->answers(static fn(): string => 'nope');

        Expect::exception(NeverMethodCalled::class)->withMessage(
            "Understudy `BookRepository` has `abort()` configured to return, but the method is declared `: never` and cannot.\n"
            . 'Configure it to throw instead: when(fn () => $double->abort(...))->throws(new SomeException())',
        );

        $repository->abort('stop');
    }

    /**
     * The signature is known when `returns()` is written, so a value the
     * declared type cannot hold is refused there — with the label — rather
     * than surfacing later as a `TypeError` naming the generated class from
     * inside the code under test. No stricter than the engine: a generated
     * method is not under `strict_types`, so `returns('5')` on `: int` stays
     * the coercion it always was, and `returns(null)` on `: void` stays the
     * idiom for "answer nothing".
     */
    #[DataProvider('refusedReturnValueProvider')]
    public function aReturnValueTheDeclaredTypeCannotHoldIsRefusedAtRegistration(callable $specify, string $exception, string $message): void
    {
        $repository = Understudy::for(BookRepository::class);

        Expect::exception($exception)->withMessage($message);

        $specify($repository);
    }

    public static function refusedReturnValueProvider(): iterable
    {
        yield 'value on void' => [
            static fn(BookRepository $r) => when(fn() => $r->save(Arg::any()))->returns(true),
            InvalidCallSpecification::class,
            'Understudy `BookRepository`: `save()` is declared `: void`, so returns() has nothing to answer with — '
            . 'the value would never be observed. Drop the value (returns(null) is allowed), or use answers()/throws() '
            . 'if the call should do something.',
        ];
        yield 'value on never' => [
            static fn(BookRepository $r) => when(fn() => $r->abort('x'))->returns('nope'),
            InvalidCallSpecification::class,
            'Understudy `BookRepository`: `abort()` is declared `: never` and cannot return. Configure what it '
            . 'throws: when(fn () => $double->abort(...))->throws(new SomeException())',
        ];
        yield 'string on nullable object' => [
            static fn(BookRepository $r) => when(fn() => $r->find(1))->returns('not a book'),
            InvalidSpecificationArgument::class,
            'Understudy `BookRepository`: returns() was given string, but `find()` is declared `: ?\\'
            . Book::class . '` and cannot answer with it.',
        ];
        yield 'null on int' => [
            static fn(BookRepository $r) => when(fn() => $r->count())->returns(null),
            InvalidSpecificationArgument::class,
            'Understudy `BookRepository`: returns() was given null, but `count()` is declared `: int` and cannot '
            . 'answer with it.',
        ];
        yield 'object on array' => [
            static fn(BookRepository $r) => when(fn() => $r->titles())->returns(new Book('x')),
            InvalidSpecificationArgument::class,
            'Understudy `BookRepository`: returns() was given ' . Book::class . ', but `titles()` is declared '
            . '`: array` and cannot answer with it.',
        ];
        yield 'second value of a chain' => [
            static fn(BookRepository $r) => when(fn() => $r->count())->returns(1, []),
            InvalidSpecificationArgument::class,
            'Understudy `BookRepository`: returns() was given array, but `count()` is declared `: int` and cannot '
            . 'answer with it.',
        ];
    }

    #[DataProvider('acceptedReturnValueProvider')]
    public function aReturnValueTheEngineWouldCoerceOrAcceptIsNotRefused(callable $specify): void
    {
        $repository = Understudy::for(BookRepository::class);

        $specify($repository);

        Assert::true(actual: true);
    }

    public static function acceptedReturnValueProvider(): iterable
    {
        yield 'null on void' => [static fn(BookRepository $r) => when(fn() => $r->save(Arg::any()))->returns(null)];
        yield 'numeric string on int' => [static fn(BookRepository $r) => when(fn() => $r->count())->returns('5')];
        yield 'bool on int' => [static fn(BookRepository $r) => when(fn() => $r->count())->returns(true)];
        yield 'null on nullable object' => [static fn(BookRepository $r) => when(fn() => $r->find(1))->returns(null)];
        yield 'object on nullable object' => [static fn(BookRepository $r) => when(fn() => $r->find(1))->returns(new Book('x'))];
        yield 'generator on Generator' => [static fn(BookRepository $r) => when(fn() => $r->stream())->returns((static function (): \Generator {
            yield 1;
        })())];
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

    public function verifyAllListsTheCallsThatDidNotMatchTheExpectation(): void
    {
        // The same report verify() renders, from the path a runner adapter
        // actually takes. These two rendered different messages for one
        // failure: verifyAll() had a sprintf of its own and showed the summary
        // line alone, so the alias table and the argument marks — the only
        // things that say WHICH call differed — were missing from every
        // adapter-reported failure.
        $repository = Understudy::for(BookRepository::class);

        expect(fn() => $repository->tag('gamma'));

        $repository->tag('alpha');
        $repository->tag('beta');

        Expect::exception(VerificationFailed::class)->withMessage(
            GoldenMessage::read('verify-all-lists-same-method-calls-with-marked-argument'),
        );

        Understudy::verifyAll();
    }

    public function verifyAllCarriesTheSameMethodCallsAsData(): void
    {
        // `observedCalls` is documented as "the calls to the same method", and
        // it is the path a reporter takes instead of parsing the message. The
        // filter that makes it true is invisible to a test that only reads the
        // rendered text — FailureReport filters again on its own way in.
        $repository = Understudy::for(BookRepository::class);

        expect(fn() => $repository->tag('gamma'));

        $repository->tag('alpha');
        $repository->count();

        try {
            Understudy::verifyAll();
        } catch (VerificationFailed $failure) {
            $observed = $failure->failures()[0]->observedCalls;

            Assert::same(array_map(
                static fn(Invocation $invocation): string => $invocation->method,
                $observed ?? [],
            ), ['tag']);

            return;
        }

        Assert::fail('verifyAll() passed with an unmet expectation');
    }

    public function verifyAllSpellsAnUncalledExpectationTheSameWayVerifyDoes(): void
    {
        // "but it was never called", not "but it was called never" — the two
        // paths disagreed on the wording of the same sentence.
        $repository = Understudy::for(BookRepository::class);

        expect(fn() => $repository->count());

        Expect::exception(VerificationFailed::class)->withMessage(
            'Understudy `BookRepository` expected `count()` to be called exactly 1 time, but it was never called.',
        );

        Understudy::verifyAll();
    }

    public function anObjectKeepsOneNameAcrossTheExpectationAndTheCallLog(): void
    {
        // The `*` marks a difference the report has to be able to show: both
        // books read the same, and the alias is what says one of them is not
        // the instance the expectation named.
        $repository = Understudy::for(BookRepository::class);
        $dune = new Book('Dune');

        $repository->save($dune);
        $repository->save(new Book('Dune'));

        Expect::exception(VerificationFailed::class)->withMessage(
            GoldenMessage::read('verify-marks-a-rebuilt-object-argument'),
        );

        verify(fn() => $repository->save($dune), times: 2);
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

        // The same answer a CALL on the same object gets, and for the same
        // reason. It used to be told it was not a double at all — or, before
        // the facade methods knew their own names, that a specification
        // closure had gone wrong somewhere.
        Expect::exception(ForgottenDouble::class)
            ->withMessageContaining('no longer known to Understudy, but `label()` was called on it')
            ->withMessageContaining('created before a reset()');

        Understudy::label($repository, 'catalogue');
    }

    /**
     * A scope drops its doubles when it closes; the message used to blame a
     * `reset()` the test never wrote. Both the call and the facade verb say
     * which it was.
     */
    public function aDoubleFromAClosedScopeSaysSo(): void
    {
        $repository = Understudy::scope(static fn(): BookRepository => Understudy::for(BookRepository::class));

        try {
            $repository->count();
            Assert::true(actual: false, message: 'Expected ForgottenDouble');
        } catch (ForgottenDouble $failure) {
            Assert::same(
                $failure->getMessage(),
                "This understudy is no longer known to Understudy, but `count()` was called on it.\n"
                . 'It was created inside a scope() that has since closed, and a scope drops its doubles when '
                . 'it ends; build the double in the scope that will use it, or outside the scope altogether.',
            );
        }

        Expect::exception(ForgottenDouble::class)
            ->withMessageContaining('`label()` was called on it')
            ->withMessageContaining('created inside a scope() that has since closed');

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
