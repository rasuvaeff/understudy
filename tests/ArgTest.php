<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Exception\InvalidCallSpecification;
use Rasuvaeff\Understudy\Matcher\AllOf;
use Rasuvaeff\Understudy\Matcher\AnyArgument;
use Rasuvaeff\Understudy\Matcher\AnyOf;
use Rasuvaeff\Understudy\Matcher\AnyTail;
use Rasuvaeff\Understudy\Matcher\ArgumentMatcher;
use Rasuvaeff\Understudy\Matcher\ArrayContaining;
use Rasuvaeff\Understudy\Matcher\BooleanValue;
use Rasuvaeff\Understudy\Matcher\Bounds;
use Rasuvaeff\Understudy\Matcher\CountBetween;
use Rasuvaeff\Understudy\Matcher\EmptyTail;
use Rasuvaeff\Understudy\Matcher\FloatInRange;
use Rasuvaeff\Understudy\Matcher\IdenticalTo;
use Rasuvaeff\Understudy\Matcher\InstanceOfType;
use Rasuvaeff\Understudy\Matcher\IntInRange;
use Rasuvaeff\Understudy\Matcher\Negated;
use Rasuvaeff\Understudy\Matcher\Operand;
use Rasuvaeff\Understudy\Matcher\QueryEquals;
use Rasuvaeff\Understudy\Matcher\Satisfying;
use Rasuvaeff\Understudy\Matcher\StringMatching;
use Rasuvaeff\Understudy\Tests\Fixture\Book;
use Rasuvaeff\Understudy\Tests\Fixture\Order;
use Rasuvaeff\Understudy\Tests\Fixture\Suit;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

// Every matcher class is listed: Infection maps mutants to tests through
// #[Covers], so a matcher left out here would generate no mutants at all and
// quietly inflate the score.
#[Test]
#[Covers(Arg::class)]
#[Covers(InvalidCallSpecification::class)]
#[Covers(AllOf::class)]
#[Covers(AnyArgument::class)]
#[Covers(AnyOf::class)]
#[Covers(AnyTail::class)]
#[Covers(ArrayContaining::class)]
#[Covers(BooleanValue::class)]
#[Covers(Bounds::class)]
#[Covers(CountBetween::class)]
#[Covers(EmptyTail::class)]
#[Covers(FloatInRange::class)]
#[Covers(IdenticalTo::class)]
#[Covers(InstanceOfType::class)]
#[Covers(IntInRange::class)]
#[Covers(Negated::class)]
#[Covers(Operand::class)]
#[Covers(QueryEquals::class)]
#[Covers(Satisfying::class)]
#[Covers(StringMatching::class)]
final class ArgTest
{
    #[DataProvider('matchProvider')]
    public function decidesWhetherAnArgumentMatches(mixed $matcher, mixed $argument, bool $expected): void
    {
        Assert::instanceOf($matcher, ArgumentMatcher::class);

        Assert::same($matcher->matches($argument), $expected);
    }

    /**
     * @return iterable<string, array{mixed, mixed, bool}>
     */
    /**
     * The rendered description is what a reader sees when an expectation
     * fails, so it is asserted rather than assumed — and asserted from inside
     * a test body on purpose: matchers built in a data provider are
     * constructed outside the coverage window, so the constructors this file
     * exercises most were never recorded as covered at all.
     */
    #[DataProvider('descriptionProvider')]
    public function describesItselfForAFailureMessage(string $expected, string $factory, mixed ...$arguments): void
    {
        /** @var ArgumentMatcher $matcher */
        $matcher = Arg::$factory(...$arguments);

        Assert::same($matcher->describe(), $expected);
    }

    public static function descriptionProvider(): iterable
    {
        yield 'any' => ['any()', 'any'];
        yield 'int unbounded' => ['int()', 'int'];
        yield 'int bounded' => ['int(min: 1, max: 5)', 'int', 1, 5];
        yield 'int lower bound only' => ['int(min: 1)', 'int', 1];
        yield 'float unbounded' => ['float()', 'float'];
        yield 'float lower bound only' => ['float(min: 0.5)', 'float', 0.5];
        yield 'float upper bound only' => ['float(max: 0.5)', 'float', null, 0.5];
        yield 'bool' => ['bool()', 'bool'];
        yield 'string unbounded' => ['string()', 'string'];
        yield 'string with pattern' => ['string(matches: /^a/)', 'string', '/^a/'];
        yield 'same scalar' => ['same(7)', 'same', 7];
        yield 'not a literal' => ['not(3)', 'not', 3];
        yield 'count bounded' => ['count(min: 1, max: 3)', 'count', 1, 3];
        yield 'which' => ['which(id, 7)', 'which', 'id', 7];
        yield 'none' => ['none()', 'none'];
        yield 'remaining' => ['remaining()', 'remaining'];
    }

    public function describesAMatcherItNegates(): void
    {
        // Negation renders what it wraps, so a nested matcher has to survive
        // into the message rather than collapsing into `not(…)`.
        Assert::same(Arg::not(Arg::int(min: 2))->describe(), 'not(int(min: 2))');
    }

    public function describesAnObjectByItsClassName(): void
    {
        // The whole name, not the short one: two `Book` classes in one suite
        // would otherwise produce the same message for different objects.
        Assert::same(Arg::same(new Book('x'))->describe(), 'same(' . Book::class . ')');
        Assert::same(Arg::instanceOf(Book::class)->describe(), 'instanceOf(' . Book::class . ')');
    }

    public function aCustomPredicateDescriptionReplacesTheDefault(): void
    {
        Assert::same(Arg::satisfies(static fn(mixed $v): bool => true)->describe(), 'satisfies(…)');
        Assert::same(
            Arg::satisfies(static fn(mixed $v): bool => true, 'a positive amount')->describe(),
            'a positive amount',
        );
    }

    public function describesTheEntriesAnArrayMustContain(): void
    {
        Assert::same(Arg::containing(['a' => 1])->describe(), "containing(['a' => 1])");
    }

    public static function matchProvider(): iterable
    {
        yield 'any accepts a value' => [Arg::any(), 5, true];
        yield 'any accepts null' => [Arg::any(), null, true];

        yield 'int accepts an int' => [Arg::int(), 5, true];
        // A numeric string is not an int: the matcher pins the declared type
        // as well as the value, which is the whole point in a strict codebase.
        yield 'int rejects a numeric string' => [Arg::int(), '5', false];
        yield 'int rejects a float' => [Arg::int(), 5.0, false];
        yield 'int honours min' => [Arg::int(min: 5), 4, false];
        yield 'int honours max' => [Arg::int(max: 5), 6, false];
        yield 'int accepts its bounds' => [Arg::int(min: 5, max: 5), 5, true];

        yield 'allOf needs every operand' => [Arg::allOf(Arg::string(), Arg::not('x')), 'x', false];
        yield 'allOf accepts what all operands take' => [Arg::allOf(Arg::string(), Arg::not('x')), 'y', true];
        yield 'allOf compares a literal operand by identity' => [Arg::allOf(Arg::int(), 5), 5, true];
        yield 'allOf rejects a literal that only looks equal' => [Arg::allOf(Arg::any(), 5), '5', false];
        yield 'allOf of one operand is that operand' => [Arg::allOf(Arg::int(min: 3)), 2, false];

        yield 'anyOf accepts the first match' => [Arg::anyOf('draft', 'review'), 'draft', true];
        yield 'anyOf accepts a later match' => [Arg::anyOf('draft', 'review'), 'review', true];
        yield 'anyOf rejects what no operand takes' => [Arg::anyOf('draft', 'review'), 'done', false];
        yield 'anyOf mixes literals and matchers' => [Arg::anyOf(1, Arg::string()), 'x', true];
        yield 'anyOf nests a combinator' => [Arg::anyOf(Arg::allOf(Arg::int(min: 5), Arg::not(7)), 'x'), 6, true];
        yield 'anyOf rejects through a nested combinator' => [Arg::anyOf(Arg::allOf(Arg::int(min: 5), Arg::not(7)), 'x'), 7, false];

        yield 'float accepts a float' => [Arg::float(), 1.5, true];
        yield 'float rejects an int' => [Arg::float(), 1, false];
        yield 'float honours bounds' => [Arg::float(min: 0.0, max: 1.0), 1.5, false];
        yield 'float accepts exactly min' => [Arg::float(min: 0.5), 0.5, true];
        yield 'float rejects just below min' => [Arg::float(min: 0.5), 0.49, false];
        yield 'float accepts exactly max' => [Arg::float(max: 0.5), 0.5, true];
        yield 'float rejects just above max' => [Arg::float(max: 0.5), 0.51, false];
        yield 'float unbounded accepts any float' => [Arg::float(), -1.5, true];
        yield 'float rejects a numeric string' => [Arg::float(), '1.5', false];
        yield 'int accepts exactly min' => [Arg::int(min: 5), 5, true];
        yield 'int accepts exactly max' => [Arg::int(max: 5), 5, true];

        yield 'string accepts a string' => [Arg::string(), 'a', true];
        yield 'string rejects an int' => [Arg::string(), 1, false];
        yield 'string honours a pattern' => [Arg::string(matches: '/^ord-/'), 'ord-1', true];
        yield 'string rejects a non-match' => [Arg::string(matches: '/^ord-/'), 'inv-1', false];

        yield 'bool accepts false' => [Arg::bool(), false, true];
        yield 'bool rejects zero' => [Arg::bool(), 0, false];

        yield 'same accepts an identical scalar' => [Arg::same(5), 5, true];
        yield 'same rejects a loose equal' => [Arg::same(5), '5', false];

        yield 'not rejects the value' => [Arg::not(5), 5, false];
        yield 'not accepts anything else' => [Arg::not(5), 6, true];
        yield 'not composes with a matcher' => [Arg::not(Arg::int()), 'a', true];
        yield 'not negates a matcher hit' => [Arg::not(Arg::int()), 1, false];

        yield 'instanceOf accepts an instance' => [Arg::instanceOf(Book::class), new Book('x'), true];
        yield 'instanceOf rejects another type' => [Arg::instanceOf(Book::class), Suit::Hearts, false];
        yield 'instanceOf rejects a scalar' => [Arg::instanceOf(Book::class), 'Book', false];

        yield 'satisfies uses the predicate' => [Arg::satisfies(static fn(mixed $v): bool => $v === 3), 3, true];
        yield 'satisfies can reject' => [Arg::satisfies(static fn(mixed $v): bool => $v === 3), 4, false];

        yield 'containing matches a map subset' => [Arg::containing(['a' => 1]), ['a' => 1, 'b' => 2], true];
        yield 'containing rejects a wrong value' => [Arg::containing(['a' => 1]), ['a' => 2], false];
        yield 'containing rejects a missing key' => [Arg::containing(['a' => 1]), ['b' => 1], false];
        yield 'containing matches list membership' => [Arg::containing([2]), [1, 2, 3], true];
        yield 'containing rejects an absent element' => [Arg::containing([9]), [1, 2, 3], false];
        yield 'containing rejects a non-array' => [Arg::containing([]), 'nope', false];

        yield 'count honours minimum' => [Arg::count(minimum: 2), [1], false];
        yield 'count honours maximum' => [Arg::count(maximum: 2), [1, 2, 3], false];
        yield 'count accepts within bounds' => [Arg::count(minimum: 1, maximum: 3), [1, 2], true];
        yield 'count rejects a non-countable' => [Arg::count(), 'abc', false];

        yield 'which compares a getter' => [Arg::which('getId', 7), new Order(id: 7), true];
        yield 'which rejects another value' => [Arg::which('getId', 7), new Order(id: 8), false];
        yield 'which rejects a missing method' => [Arg::which('nope', 1), new Order(), false];
        // A public property is not a getter: which() calls methods only.
        yield 'which rejects a property of the same name' => [Arg::which('title', 'Dune'), new Book('Dune'), false];
        yield 'which rejects a non-object' => [Arg::which('getId', 7), 'Dune', false];
    }

    #[DataProvider('describeProvider')]
    public function describesItselfForFailureMessages(mixed $matcher, string $expected): void
    {
        \assert($matcher instanceof ArgumentMatcher);

        Assert::same($matcher->describe(), $expected);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public function anEmptyCombinatorIsRefusedRatherThanMatchingEverything(): void
    {
        Expect::exception(InvalidCallSpecification::class)->withMessage(
            "`Arg::allOf()` was given no operands, so it would match every argument.\n"
            . 'Pass what the argument has to satisfy, or use Arg::any() to accept anything.',
        );

        Arg::allOf();
    }

    public function anEmptyDisjunctionSaysItWouldMatchNothing(): void
    {
        Expect::exception(InvalidCallSpecification::class)->withMessage(
            "`Arg::anyOf()` was given no operands, so it would match no argument at all.\n"
            . 'Pass what the argument has to satisfy, or use Arg::any() to accept anything.',
        );

        Arg::anyOf();
    }

    /**
     * A tail matcher answers `true` to every single argument, so a combinator
     * holding one would be a no-op operand in a conjunction and a
     * match-anything in a disjunction — silently, which is the worst of it.
     */
    public function aTailMatcherCannotBeAnOperand(): void
    {
        Expect::exception(InvalidCallSpecification::class)->withMessage(
            "`remaining()` stands for the whole variadic tail, and `Arg::allOf()` combines matchers "
            . "for one argument, so it cannot hold one.\n"
            . 'Put the tail matcher last on its own, and combine the arguments before it.',
        );

        Arg::allOf(Arg::string(), Arg::remaining());
    }

    public function anEmptyTailMatcherCannotBeAnOperandEither(): void
    {
        Expect::exception(InvalidCallSpecification::class)->withMessage(
            "`none()` stands for the whole variadic tail, and `Arg::anyOf()` combines matchers "
            . "for one argument, so it cannot hold one.\n"
            . 'Put the tail matcher last on its own, and combine the arguments before it.',
        );

        Arg::anyOf(Arg::none());
    }

    public static function describeProvider(): iterable
    {
        yield 'any' => [Arg::any(), 'any()'];
        yield 'int unbounded' => [Arg::int(), 'int()'];
        yield 'int with min' => [Arg::int(min: 1), 'int(min: 1)'];
        yield 'int with max' => [Arg::int(max: 9), 'int(max: 9)'];
        yield 'int with both' => [Arg::int(min: 1, max: 9), 'int(min: 1, max: 9)'];
        yield 'float' => [Arg::float(min: 0.5), 'float(min: 0.5)'];
        yield 'string' => [Arg::string(), 'string()'];
        yield 'string with pattern' => [Arg::string(matches: '/^a/'), 'string(matches: /^a/)'];
        yield 'bool' => [Arg::bool(), 'bool()'];
        yield 'same' => [Arg::same('a'), "same('a')"];
        yield 'not a literal' => [Arg::not(5), 'not(5)'];
        yield 'not a matcher' => [Arg::not(Arg::any()), 'not(any())'];
        yield 'instanceOf' => [Arg::instanceOf(Book::class), 'instanceOf(' . Book::class . ')'];
        yield 'satisfies default' => [Arg::satisfies(static fn(): bool => true), 'satisfies(…)'];
        yield 'satisfies described' => [Arg::satisfies(static fn(): bool => true, 'isEven()'), 'isEven()'];
        yield 'containing' => [Arg::containing(['a' => 1]), "containing(['a' => 1])"];
        yield 'count' => [Arg::count(maximum: 10), 'count(max: 10)'];
        yield 'which' => [Arg::which('getId', 7), 'which(getId, 7)'];
        yield 'allOf' => [Arg::allOf(Arg::string(), Arg::not('x')), "allOf(string(), not('x'))"];
        yield 'anyOf of literals' => [Arg::anyOf('draft', 'review'), "anyOf('draft', 'review')"];
        yield 'anyOf mixing a literal and a matcher' => [Arg::anyOf(1, Arg::int(min: 5)), 'anyOf(1, int(min: 5))'];
        yield 'none' => [Arg::none(), 'none()'];
        yield 'remaining' => [Arg::remaining(), 'remaining()'];
    }

    public function aGetterThatThrowsIsAMismatchNotAnError(): void
    {
        // Matching runs while the code under test is executing; a matcher must
        // never be the thing that breaks it.
        $exploding = new class {
            public function value(): string
            {
                throw new \RuntimeException('boom');
            }
        };

        $matcher = Arg::which('value', 'x');
        \assert($matcher instanceof ArgumentMatcher);

        Assert::false($matcher->matches($exploding));
    }

    public function aGetterNeedingArgumentsIsNotCalled(): void
    {
        $target = new class {
            public function value(string $required): string
            {
                return $required;
            }
        };

        $matcher = Arg::which('value', 'x');
        \assert($matcher instanceof ArgumentMatcher);

        Assert::false($matcher->matches($target));
    }

    public function aStaticGetterIsNotCalled(): void
    {
        $target = new class {
            public static function value(): string
            {
                return 'x';
            }
        };

        $matcher = Arg::which('value', 'x');
        \assert($matcher instanceof ArgumentMatcher);

        Assert::false($matcher->matches($target));
    }

    public function countAcceptsACountableObject(): void
    {
        $countable = new \ArrayObject([1, 2]);

        $matcher = Arg::count(minimum: 2, maximum: 2);
        \assert($matcher instanceof ArgumentMatcher);

        Assert::true($matcher->matches($countable));
    }
}
