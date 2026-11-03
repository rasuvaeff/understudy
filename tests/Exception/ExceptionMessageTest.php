<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Exception;

use Rasuvaeff\Understudy\Exception\AmbiguousDefaultFactory;
use Rasuvaeff\Understudy\Exception\BypassUnavailable;
use Rasuvaeff\Understudy\Exception\CannotWire;
use Rasuvaeff\Understudy\Exception\ConflictingExpectation;
use Rasuvaeff\Understudy\Exception\ContextOwnershipViolation;
use Rasuvaeff\Understudy\Exception\ForgottenDouble;
use Rasuvaeff\Understudy\Exception\ForwardingTargetMismatch;
use Rasuvaeff\Understudy\Exception\InvalidCallSpecification;
use Rasuvaeff\Understudy\Exception\InvalidDefaultValue;
use Rasuvaeff\Understudy\Exception\InvalidSpecificationArgument;
use Rasuvaeff\Understudy\Exception\OriginalCallUnavailable;
use Rasuvaeff\Understudy\Exception\OriginalReturnTypeViolation;
use Rasuvaeff\Understudy\Tests\Fixture\Book;
use Rasuvaeff\Understudy\Tests\Fixture\Draft;
use Rasuvaeff\Understudy\Tests\Fixture\Order;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * The wording of every failure a user can read, pinned word for word.
 *
 * A message is the only documentation a refusal carries, and each sentence in it
 * is a claim about what to do next. Any edit to the text is therefore an edit to
 * this file, and the diff is the wording review.
 */
#[Test]
#[Covers(AmbiguousDefaultFactory::class)]
#[Covers(BypassUnavailable::class)]
#[Covers(CannotWire::class)]
#[Covers(ConflictingExpectation::class)]
#[Covers(ContextOwnershipViolation::class)]
#[Covers(ForgottenDouble::class)]
#[Covers(ForwardingTargetMismatch::class)]
#[Covers(InvalidCallSpecification::class)]
#[Covers(InvalidDefaultValue::class)]
#[Covers(InvalidSpecificationArgument::class)]
#[Covers(OriginalCallUnavailable::class)]
#[Covers(OriginalReturnTypeViolation::class)]
final class ExceptionMessageTest
{
    #[DataProvider('messageProvider')]
    public function eachFactoryWordsItsMessageAsPublished(\Closure $build, string $expected): void
    {
        // The expectations are nowdocs: a checkout that converts line endings
        // turns their newlines into CRLF, while the messages always carry LF.
        Assert::same($build()->getMessage(), str_replace("\r\n", "\n", $expected));
    }

    /**
     * @return iterable<string, array{\Closure(): \Throwable, string}>
     */
    public static function messageProvider(): iterable
    {
        yield 'InvalidCallSpecification::noCallRecorded' => [
            InvalidCallSpecification::noCallRecorded(...),
            <<<'TXT'
                The specification closure did not call a method on an understudy. It must contain exactly one direct call, for example: when(fn () => $repository->find(123))
                TXT,
        ];

        yield 'InvalidCallSpecification::moreThanOneCall' => [
            static fn(): \Throwable => InvalidCallSpecification::moreThanOneCall('first', 'one', 'last', 'two'),
            <<<'TXT'
                The specification closure calls `one()` on understudy `first` and then `two()` on understudy `last`. A closure must contain exactly one direct call on a double; with two, one of them would be specified silently and the other not at all. Stub each call in a when() of its own, and where one call feeds the arguments of another pass a literal or a matcher instead, for example: when(fn () => $repository->find(Arg::any()))
                TXT,
        ];

        yield 'InvalidCallSpecification::returnsOnVoid' => [
            static fn(): \Throwable => InvalidCallSpecification::returnsOnVoid('mailer', 'send'),
            <<<'TXT'
                Understudy `mailer`: `send()` is declared `: void`, so returns() has nothing to answer with — the value would never be observed. Drop the value (returns(null) is allowed), or use answers()/throws() if the call should do something.
                TXT,
        ];

        yield 'InvalidCallSpecification::returnsOnNever' => [
            static fn(): \Throwable => InvalidCallSpecification::returnsOnNever('mailer', 'halt'),
            <<<'TXT'
                Understudy `mailer`: `halt()` is declared `: never` and cannot return. Configure what it throws: when(fn () => $double->halt(...))->throws(new SomeException())
                TXT,
        ];

        yield 'InvalidCallSpecification::notADouble' => [
            static fn(): \Throwable => InvalidCallSpecification::notADouble('verify'),
            <<<'TXT'
                Understudy::verify() expects an understudy created by Understudy::for(). This object is not one.
                TXT,
        ];

        yield 'InvalidCallSpecification::neverBesideACount' => [
            static fn(): \Throwable => InvalidCallSpecification::neverBesideACount('times'),
            <<<'TXT'
                `never: true` says the call never happened, and `times` says how often it did. Keep the one you mean.
                TXT,
        ];

        yield 'InvalidCallSpecification::exactCountBesideABound' => [
            InvalidCallSpecification::exactCountBesideABound(...),
            <<<'TXT'
                `times` is an exact count, so a `minimum` or `maximum` beside it has nothing left to constrain. Use one or the other.
                TXT,
        ];

        yield 'InvalidCallSpecification::misplacedTailMatcher' => [
            static fn(): \Throwable => InvalidCallSpecification::misplacedTailMatcher('save', 1, 'Arg::rest()'),
            <<<'TXT'
                `Arg::rest()` stands for the whole variadic tail, so it may only be the last argument, but it was given as argument #2 of `save()`.
                Move it to the end, or use Arg::any() to match that one argument.
                TXT,
        ];

        yield 'InvalidCallSpecification::emptyCombinator allOf' => [
            static fn(): \Throwable => InvalidCallSpecification::emptyCombinator('allOf'),
            <<<'TXT'
                `Arg::allOf()` was given no operands, so it would match every argument.
                Pass what the argument has to satisfy, or use Arg::any() to accept anything.
                TXT,
        ];

        yield 'InvalidCallSpecification::emptyCombinator anyOf' => [
            static fn(): \Throwable => InvalidCallSpecification::emptyCombinator('anyOf'),
            <<<'TXT'
                `Arg::anyOf()` was given no operands, so it would match no argument at all.
                Pass what the argument has to satisfy, or use Arg::any() to accept anything.
                TXT,
        ];

        yield 'InvalidCallSpecification::guardAlreadyNarrowed without description' => [
            static fn(): \Throwable => InvalidCallSpecification::guardAlreadyNarrowed('find', null),
            <<<'TXT'
                `find` already carries a `where()` guard. One call, one guard: put both conditions in the one closure.
                TXT,
        ];

        yield 'InvalidCallSpecification::guardAlreadyNarrowed with description' => [
            static fn(): \Throwable => InvalidCallSpecification::guardAlreadyNarrowed('find', 'id > 0'),
            <<<'TXT'
                `find` already carries a `where()` guard (`id > 0`). One call, one guard: put both conditions in the one closure.
                TXT,
        ];

        yield 'InvalidCallSpecification::tailMatcherInCombinator' => [
            static fn(): \Throwable => InvalidCallSpecification::tailMatcherInCombinator('allOf', 'Arg::rest()'),
            <<<'TXT'
                `Arg::rest()` stands for the whole variadic tail, and `Arg::allOf()` combines matchers for one argument, so it cannot hold one.
                Put the tail matcher last on its own, and combine the arguments before it.
                TXT,
        ];

        yield 'InvalidCallSpecification::matcherInsideArray' => [
            static fn(): \Throwable => InvalidCallSpecification::matcherInsideArray('save', 1, 'Arg::any()'),
            <<<'TXT'
                `Arg::any()` sits inside the array given as argument #2 of `save()`, where it is compared by identity and can never match.
                Describe the array with Arg::containing([...]), which reads matchers in its entries, or the whole argument with Arg::satisfies().
                TXT,
        ];

        yield 'InvalidCallSpecification::captorInCombinator' => [
            static fn(): \Throwable => InvalidCallSpecification::captorInCombinator('anyOf'),
            <<<'TXT'
                A captor records the argument it stands for, and `Arg::anyOf()` builds one matcher out of others, so a captor inside it would match without ever recording.
                Put the captor in the argument position itself, or read the calls with Understudy::calls().
                TXT,
        ];

        yield 'InvalidCallSpecification::emptySequence' => [
            InvalidCallSpecification::emptySequence(...),
            <<<'TXT'
                Understudy::expectSequence() needs at least one call: an empty protocol claims nothing and would refuse every call on no double at all
                TXT,
        ];

        yield 'InvalidCallSpecification::protocolAlreadyArmed' => [
            static fn(): \Throwable => InvalidCallSpecification::protocolAlreadyArmed(2, 5),
            <<<'TXT'
                A protocol is already armed and is waiting on step 2 of 5. Two of them naming the same understudy would each judge every call on it and could disagree about the same call — finish this one, or describe both phases as a single protocol
                TXT,
        ];

        yield 'InvalidCallSpecification::incompleteSpecification' => [
            static fn(): \Throwable => InvalidCallSpecification::incompleteSpecification('save', 1, 3),
            <<<'TXT'
                The specification for `save()` passed 1 of its 3 arguments, and the ones it left out are not all optional.
                Spell every required argument, or say the rest does not matter by ending with Arg::rest().
                TXT,
        ];

        yield 'InvalidCallSpecification::omittedBeforeSpecified' => [
            static fn(): \Throwable => InvalidCallSpecification::omittedBeforeSpecified('save', 0, 2),
            <<<'TXT'
                The specification for `save()` omitted argument #1 — which the contract declares required — but specified argument #3 after it.
                A specification spells its required arguments in order — use Arg::any() for one that does not matter.
                TXT,
        ];

        yield 'InvalidCallSpecification::omittedTailNeedsRest' => [
            static fn(): \Throwable => InvalidCallSpecification::omittedTailNeedsRest('save', 'Arg::rest()'),
            <<<'TXT'
                `Arg::rest()` describes a variadic tail, not parameters left unspelled, and the specification for `save()` stopped before its parameters ran out.
                End with Arg::rest() to say the remaining parameters do not matter.
                TXT,
        ];

        yield 'InvalidCallSpecification::closureFailed' => [
            static fn(): \Throwable => InvalidCallSpecification::closureFailed(new \RuntimeException('boom')),
            <<<'TXT'
                The specification closure threw before it reached an understudy: boom
                TXT,
        ];

        yield 'InvalidCallSpecification::staticMethodCalled' => [
            static fn(): \Throwable => InvalidCallSpecification::staticMethodCalled('create'),
            <<<'TXT'
                Static method `create()` cannot be called on an understudy because static calls have no instance state.
                Inject an instance dependency and double that contract instead.
                TXT,
        ];

        yield 'ForgottenDouble::afterReset' => [
            static fn(): \Throwable => ForgottenDouble::afterReset('find'),
            <<<'TXT'
                This understudy is no longer known to Understudy, but `find()` was called on it.
                It was created before a reset(); create doubles inside the test that uses them rather than sharing one across tests.
                TXT,
        ];

        yield 'ForgottenDouble::afterScope' => [
            static fn(): \Throwable => ForgottenDouble::afterScope('find'),
            <<<'TXT'
                This understudy is no longer known to Understudy, but `find()` was called on it.
                It was created inside a scope() that has since closed, and a scope drops its doubles when it ends; build the double in the scope that will use it, or outside the scope altogether.
                TXT,
        ];

        yield 'ForgottenDouble::onPurpose' => [
            static fn(): \Throwable => ForgottenDouble::onPurpose('find'),
            <<<'TXT'
                This understudy was retired with Understudy::forget(), but `find()` was called on it.
                A forgotten double is gone for good; build a new one instead of calling this one.
                TXT,
        ];

        yield 'ForgottenDouble::propertyAfterReset' => [
            static fn(): \Throwable => ForgottenDouble::propertyAfterReset('title'),
            <<<'TXT'
                This understudy is no longer known to Understudy, but its property `$title` was touched.
                It was created before a reset(); create doubles inside the test that uses them rather than sharing one across tests.
                TXT,
        ];

        yield 'ForgottenDouble::retired' => [
            ForgottenDouble::retired(...),
            <<<'TXT'
                This understudy was retired with Understudy::forget() and can no longer be asked anything — not calls, not verification. A replacement built afterwards is a different object; ask that one.
                TXT,
        ];

        yield 'ForgottenDouble::fromDefaultFactory' => [
            static fn(): \Throwable => ForgottenDouble::fromDefaultFactory(Book::class),
            <<<'TXT'
                The default factory for `Rasuvaeff\Understudy\Tests\Fixture\Book` answered with an understudy that is no longer known to Understudy.
                It was created before a reset(); register the factory inside the test that uses it rather than sharing one double across tests.
                TXT,
        ];

        yield 'BypassUnavailable::alreadyLoaded' => [
            static fn(): \Throwable => BypassUnavailable::alreadyLoaded(Book::class),
            <<<'TXT'
                `Rasuvaeff\Understudy\Tests\Fixture\Book` is already loaded, so its `final` cannot be lifted any more.
                A class is read from disk once per process, and the transform happens as it is read.
                - Call Understudy::bypassFinals() from your test bootstrap, before Composer autoloads it.
                - Or double an interface it implements, which needs no bypass at all.
                TXT,
        ];

        yield 'BypassUnavailable::notAClass' => [
            static fn(): \Throwable => BypassUnavailable::notAClass(Book::class, 'an enum'),
            <<<'TXT'
                `Rasuvaeff\Understudy\Tests\Fixture\Book` is an enum, and lifting `final` would not make it extendable.
                Bypass exists for a final *class* standing between a test and the code under test.
                TXT,
        ];

        yield 'BypassUnavailable::foreignWrapper' => [
            static fn(): \Throwable => BypassUnavailable::foreignWrapper('somebody'),
            <<<'TXT'
                Something else is already serving `file://` through a user-space wrapper (somebody).
                Replacing that wrapper would silently disable whatever it does, so understudy will not.
                - Call Understudy::bypassFinals() before the other wrapper is installed.
                - Or double an interface, which needs no wrapper at all.
                TXT,
        ];

        yield 'ConflictingExpectation::claimAfterStub' => [
            static fn(): \Throwable => ConflictingExpectation::claimAfterStub('repo', 'find(1)'),
            <<<'TXT'
                Understudy `repo` already has `find(1)` stubbed, and a separate expect() for the same call would not compose with it: the expectation would take the dispatch and answer the mode default, silently discarding the stub. Declare one expectation — expect(...)->returns(...), or count the stub with when(...)->times(...).
                TXT,
        ];

        yield 'ConflictingExpectation::stubAfterCountedExpectation' => [
            static fn(): \Throwable => ConflictingExpectation::stubAfterCountedExpectation('repo', 'find(1)'),
            <<<'TXT'
                Understudy `repo` already counts `find(1)`, and a later stub for the same call would not compose with it: the stub would take the dispatch and starve the count, which verifyAll() then reports as "called never" about a call that did happen. Give the expectation its behaviour instead: expect(...)->returns(...).
                TXT,
        ];

        yield 'ConflictingExpectation::duplicateCountedExpectation' => [
            static fn(): \Throwable => ConflictingExpectation::duplicateCountedExpectation('repo', 'find(1)'),
            <<<'TXT'
                Understudy `repo` already counts `find(1)`, and a second expectation for the same call would not compose with it: the newer one would take every match and the older count is starved. Declare the count once — expect(...)->times(...).
                TXT,
        ];

        yield 'CannotWire::notAConcreteClass' => [
            static fn(): \Throwable => CannotWire::notAConcreteClass(Book::class, 'it is an interface.'),
            <<<'TXT'
                Cannot wire `Rasuvaeff\Understudy\Tests\Fixture\Book`: it is an interface.
                wire() builds a real subject out of doubled dependencies; the subject itself is not a double.
                TXT,
        ];

        yield 'CannotWire::inaccessibleConstructor' => [
            static fn(): \Throwable => CannotWire::inaccessibleConstructor(Book::class, 'private'),
            <<<'TXT'
                Cannot wire `Rasuvaeff\Understudy\Tests\Fixture\Book`: its constructor is private.
                Use the named constructor the class offers, or build the subject yourself and double its dependencies with Understudy::for().
                TXT,
        ];

        yield 'CannotWire::unknownOverride' => [
            static fn(): \Throwable => CannotWire::unknownOverride(Book::class, 'nope', '$title, $author'),
            <<<'TXT'
                Cannot wire `Rasuvaeff\Understudy\Tests\Fixture\Book`: there is no constructor parameter named `nope`.
                The constructor takes: $title, $author.
                TXT,
        ];

        yield 'CannotWire::incompatibleOverride' => [
            static fn(): \Throwable => CannotWire::incompatibleOverride(Book::class, 'title', 'string', 'int'),
            <<<'TXT'
                Cannot wire `Rasuvaeff\Understudy\Tests\Fixture\Book`: the override for `$title` has type `int`, and the constructor declares `string`.
                The check happens before the constructor runs, so a wrong type is reported here rather than as a TypeError from inside the subject.
                TXT,
        ];

        yield 'CannotWire::undecidableParameter' => [
            static fn(): \Throwable => CannotWire::undecidableParameter(Book::class, 'title', 'string', 'a scalar has no double.'),
            <<<'TXT'
                Cannot wire `Rasuvaeff\Understudy\Tests\Fixture\Book`: nothing can be decided for `$title` (`string`) — a scalar has no double.
                Pass it yourself: Understudy::wire(Book::class, ['title' => $value]).
                TXT,
        ];

        yield 'CannotWire::referenceParameter' => [
            static fn(): \Throwable => CannotWire::referenceParameter(Book::class, 'title'),
            <<<'TXT'
                Cannot wire `Rasuvaeff\Understudy\Tests\Fixture\Book`: `$title` is taken by reference.
                Overrides are values, and passing one would quietly promise a reference semantics wire() does not have. Build the subject yourself.
                TXT,
        ];

        yield 'OriginalReturnTypeViolation::foreignInstance' => [
            static fn(): \Throwable => OriginalReturnTypeViolation::foreignInstance('ledger', 'copy', Book::class),
            <<<'TXT'
                Understudy `ledger` forwarded `copy()`, which returned a different `Rasuvaeff\Understudy\Tests\Fixture\Book` instead of the instance it was called on.
                The method declares `static`, so the double must return itself, and another object of the real class is not a double. Configure the method instead: when(fn () => $double->copy(...))->returns($double).
                TXT,
        ];

        yield 'OriginalReturnTypeViolation::foreignSelf' => [
            static fn(): \Throwable => OriginalReturnTypeViolation::foreignSelf('ledger', 'copy'),
            <<<'TXT'
                Understudy `ledger` ran `copy()` inline, and the real body built a new instance of itself — a `new static` or `new self`.
                It is of the double's own class, so the return type accepts it, but no context knows it and every call on it would fail as an unregistered double. Configure the method instead: when(fn () => $double->copy(...))->returns($double).
                TXT,
        ];

        yield 'OriginalCallUnavailable::forMode' => [
            static fn(): \Throwable => OriginalCallUnavailable::forMode('ledger'),
            <<<'TXT'
                Understudy `ledger` was asked to forward, and has no real instance to forward to.
                Pass one: Understudy::forwarding($double, $real). The shorthand Understudy::forwarding($double) only turns the mode on for a double built from an instance with Understudy::for($real).
                TXT,
        ];

        yield 'OriginalCallUnavailable::withoutTarget' => [
            static fn(): \Throwable => OriginalCallUnavailable::withoutTarget('ledger', 'copy'),
            <<<'TXT'
                Understudy `ledger` has no real instance to delegate `copy()` to.
                Give it one: Understudy::forwarding($double, $real) — calling the parent implementation on a double whose constructor never ran is not a safe substitute.
                TXT,
        ];

        yield 'ForwardingTargetMismatch::understudyTarget' => [
            static fn(): \Throwable => ForwardingTargetMismatch::understudyTarget('ledger'),
            <<<'TXT'
                Understudy `ledger` cannot forward to an understudy.
                Every call would come straight back to a dispatcher, and an unmatched one would keep coming back until the stack runs out. Forward to the real object instead.
                TXT,
        ];

        yield 'ForwardingTargetMismatch::missingContract' => [
            static fn(): \Throwable => ForwardingTargetMismatch::missingContract('ledger', Book::class, Draft::class),
            <<<'TXT'
                Understudy `ledger` stands in for `Rasuvaeff\Understudy\Tests\Fixture\Book`, and `Rasuvaeff\Understudy\Tests\Fixture\Draft` is not one.
                A forwarding target has to satisfy every contract of the double, or a call the double accepts would not exist on the instance it delegates to.
                TXT,
        ];

        yield 'ForwardingTargetMismatch::alreadyInline' => [
            static fn(): \Throwable => ForwardingTargetMismatch::alreadyInline('ledger', Book::class, Draft::class),
            <<<'TXT'
                Understudy `ledger` already runs the bodies of `Rasuvaeff\Understudy\Tests\Fixture\Book` on itself, and cannot be re-pointed at `Rasuvaeff\Understudy\Tests\Fixture\Draft`.
                The first instance's state was copied onto the double when forwarding was turned on; a second copy would overwrite everything the real code has written since, and a `readonly` property cannot be written twice at all. Build a second double for the other instance.
                TXT,
        ];

        yield 'AmbiguousDefaultFactory::between' => [
            static fn(): \Throwable => AmbiguousDefaultFactory::between(Book::class, [Draft::class, Order::class]),
            <<<'TXT'
                More than one default factory is equally close to `Rasuvaeff\Understudy\Tests\Fixture\Book`: `Rasuvaeff\Understudy\Tests\Fixture\Draft`, `Rasuvaeff\Understudy\Tests\Fixture\Order`.
                Register one for `Rasuvaeff\Understudy\Tests\Fixture\Book` itself — resolution by distance is what keeps the answer independent of the order the factories were registered in, and a tie has no order to fall back on.
                TXT,
        ];

        yield 'InvalidDefaultValue::ofWrongType' => [
            static fn(): \Throwable => InvalidDefaultValue::ofWrongType(Book::class, 'int'),
            <<<'TXT'
                The default factory registered for `Rasuvaeff\Understudy\Tests\Fixture\Book` produced a value of type `int`.
                A factory has to return something the requested type can hold, or the double answers with a value the code under test cannot use.
                TXT,
        ];

        yield 'ContextOwnershipViolation::forDouble' => [
            ContextOwnershipViolation::forDouble(...),
            <<<'TXT'
                This understudy belongs to a different runtime context. Configure and verify it in the scope or Fiber that created it; only normal method calls may cross context boundaries.
                TXT,
        ];

        yield 'InvalidSpecificationArgument::unknownArgument positional' => [
            static fn(): \Throwable => InvalidSpecificationArgument::unknownArgument('save', 2, ['title', 'author']),
            <<<'TXT'
                `save()` has no argument #3. It takes: $title, $author
                TXT,
        ];

        yield 'InvalidSpecificationArgument::unknownArgument named' => [
            static fn(): \Throwable => InvalidSpecificationArgument::unknownArgument('save', 'isbn', ['title']),
            <<<'TXT'
                `save()` has no argument named `$isbn`. It takes: $title
                TXT,
        ];

        yield 'InvalidSpecificationArgument::unknownArgument none' => [
            static fn(): \Throwable => InvalidSpecificationArgument::unknownArgument('save', 0, []),
            <<<'TXT'
                `save()` has no argument #1. It takes: none
                TXT,
        ];

        yield 'InvalidSpecificationArgument::maximumBelowMinimum' => [
            static fn(): \Throwable => InvalidSpecificationArgument::maximumBelowMinimum(3, 1),
            <<<'TXT'
                A maximum call count cannot be below the minimum, got minimum 3 and maximum 1
                TXT,
        ];

        yield 'InvalidSpecificationArgument::negativeCount' => [
            static fn(): \Throwable => InvalidSpecificationArgument::negativeCount(-2),
            <<<'TXT'
                A call count cannot be negative, got -2
                TXT,
        ];

        yield 'InvalidSpecificationArgument::noReturnValues' => [
            InvalidSpecificationArgument::noReturnValues(...),
            <<<'TXT'
                returns() needs at least one value
                TXT,
        ];

        yield 'InvalidSpecificationArgument::returnValueOfWrongType' => [
            static fn(): \Throwable => InvalidSpecificationArgument::returnValueOfWrongType('repo', 'find', 'int', 'string'),
            <<<'TXT'
                Understudy `repo`: returns() was given string, but `find()` is declared `: int` and cannot answer with it.
                TXT,
        ];

        yield 'InvalidSpecificationArgument::unknownType' => [
            static fn(): \Throwable => InvalidSpecificationArgument::unknownType('Nope\\Missing'),
            <<<'TXT'
                Arg::instanceOf(`Nope\Missing`) can never match: no such class or interface is loadable.
                TXT,
        ];

        yield 'InvalidSpecificationArgument::invertedBounds' => [
            static fn(): \Throwable => InvalidSpecificationArgument::invertedBounds('between', 5, 1.5),
            <<<'TXT'
                `Arg::between()` was given a minimum of 5 and a maximum of 1.5, so it describes an empty range and would match no argument at all.
                Order the bounds the other way, or leave one of them out to keep that side open.
                TXT,
        ];

        yield 'InvalidSpecificationArgument::invalidPattern without reason' => [
            static fn(): \Throwable => InvalidSpecificationArgument::invalidPattern('/(/', null),
            <<<'TXT'
                `Arg::string()` was given `/(/`, which is not a valid PCRE pattern.
                It would match no string and would raise a warning inside the code under test on every call. Check the delimiters and the escaping.
                TXT,
        ];

        yield 'InvalidSpecificationArgument::invalidPattern with reason' => [
            static fn(): \Throwable => InvalidSpecificationArgument::invalidPattern('/(/', 'missing closing parenthesis'),
            <<<'TXT'
                `Arg::string()` was given `/(/`, which is not a valid PCRE pattern: missing closing parenthesis.
                It would match no string and would raise a warning inside the code under test on every call. Check the delimiters and the escaping.
                TXT,
        ];
    }
}
