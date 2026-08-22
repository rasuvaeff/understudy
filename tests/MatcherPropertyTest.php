<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Matcher\ArgumentMatcher;
use Rasuvaeff\Understudy\Tests\Support\MatcherGenerators;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The algebra the matchers obey. A matcher is a predicate, so the laws are the
 * ones predicates have: negation is an involution, a range is a closed
 * interval, containment is monotone under adding entries.
 */
#[Test]
#[Covers(Arg::class)]
final class MatcherPropertyTest
{
    /**
     * Double negation is the identity, for a literal and for a matcher alike.
     */
    #[Property(runs: 300)]
    public function negationIsAnInvolution(mixed $value, mixed $subject): void
    {
        $once = $this->matcher(Arg::not($value));
        $twice = $this->matcher(Arg::not(Arg::not($value)));

        Assert::same($twice->matches($subject), !$once->matches($subject));
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function negationIsAnInvolutionGenerators(): array
    {
        return [
            'value' => MatcherGenerators::anyScalar(),
            'subject' => MatcherGenerators::anyScalar(),
        ];
    }

    /**
     * `any()` is the top of the lattice: nothing is outside it, `null`
     * included.
     */
    #[Property(runs: 200)]
    public function anyAcceptsEverything(mixed $subject): void
    {
        Assert::true($this->matcher(Arg::any())->matches($subject));
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function anyAcceptsEverythingGenerators(): array
    {
        return [
            'subject' => MatcherGenerators::anyValue(),
        ];
    }

    /**
     * `same()` is reflexive, and `not(same())` is its exact complement.
     */
    #[Property(runs: 300)]
    public function identityIsReflexiveAndItsNegationExact(mixed $value): void
    {
        Assert::true($this->matcher(Arg::same($value))->matches($value));
        Assert::false($this->matcher(Arg::not(Arg::same($value)))->matches($value));
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function identityIsReflexiveAndItsNegationExactGenerators(): array
    {
        return ['value' => MatcherGenerators::anyScalar()];
    }

    /**
     * `int(min:, max:)` accepts a value if and only if it is an int inside the
     * closed interval — the same law `Cardinality` obeys, on the other side of
     * the library.
     */
    #[Property(runs: 400)]
    public function anIntRangeIsAClosedInterval(int $min, int $slack, int $subject): void
    {
        $max = $min + $slack;
        $inside = $subject >= $min && $subject <= $max;

        Classify::cover($inside, 'inside the range', 15);
        Classify::cover(!$inside, 'outside the range', 15);

        Assert::same($this->matcher(Arg::int(min: $min, max: $max))->matches($subject), $inside);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function anIntRangeIsAClosedIntervalGenerators(): array
    {
        return [
            // Constructed, not filtered: a max below the min is not what this
            // property is about.
            'min' => Gen::intBetween(-10, 10),
            'slack' => Gen::intBetween(0, 20),
            'subject' => Gen::intBetween(-15, 25),
        ];
    }

    /**
     * An unbounded range accepts every int, and no non-int at all.
     */
    #[Property(runs: 300)]
    public function anUnboundedIntRangeIsExactlyTheIntType(mixed $subject): void
    {
        Assert::same($this->matcher(Arg::int())->matches($subject), is_int($subject));
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function anUnboundedIntRangeIsExactlyTheIntTypeGenerators(): array
    {
        return [
            // Numeric strings and floats are the interesting near-misses: a
            // matcher that accepted them would hide the bug a strict test is
            // looking for, so they get half the draws.
            'subject' => Gen::frequency([
                [2, Gen::int()],
                [1, Gen::map(Gen::int(), static fn(int $n): string => (string) $n)],
                [1, Gen::float()],
                [1, Gen::bool()],
            ]),
        ];
    }

    /**
     * Containment is monotone: an array that contains a subset still contains
     * it after more entries are added.
     */
    #[Property(runs: 300)]
    public function containmentSurvivesAddedEntries(array $subset, array $extra): void
    {
        $whole = $subset + $extra;

        Assert::true($this->matcher(Arg::containing($subset))->matches($whole));
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function containmentSurvivesAddedEntriesGenerators(): array
    {
        return [
            // Disjoint alphabets so the two dictionaries cannot collide on a
            // key, which would make the union trivially equal to the subset.
            'subset' => Gen::dictOf(Gen::stringFrom('abcd', 1, 4), Gen::int(), 0, 4),
            'extra' => Gen::dictOf(Gen::stringFrom('wxyz', 1, 4), Gen::int(), 0, 4),
        ];
    }

    /**
     * Every array contains itself, and the empty subset is contained in all of
     * them.
     */
    #[Property(runs: 200)]
    public function containmentIsReflexiveAndTheEmptySubsetIsUniversal(array $entries): void
    {
        Assert::true($this->matcher(Arg::containing($entries))->matches($entries));
        Assert::true($this->matcher(Arg::containing([]))->matches($entries));
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function containmentIsReflexiveAndTheEmptySubsetIsUniversalGenerators(): array
    {
        return ['entries' => Gen::dictOf(Gen::stringFrom('abcde', 1, 5), Gen::int(), 0, 6)];
    }

    /**
     * `count()` agrees with `count()`: the matcher is the interval, the array
     * length is the value.
     */
    #[Property(runs: 300)]
    public function countMatchesTheActualSize(array $items, int $min, int $slack): void
    {
        $max = $min + $slack;
        $size = count($items);

        Assert::same(
            $this->matcher(Arg::count(minimum: $min, maximum: $max))->matches($items),
            $size >= $min && $size <= $max,
        );
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function countMatchesTheActualSizeGenerators(): array
    {
        return [
            'items' => Gen::arrayOf(Gen::int(), 0, 8),
            'min' => Gen::intBetween(0, 5),
            'slack' => Gen::intBetween(0, 5),
        ];
    }

    /**
     * A pattern matcher agrees with the pattern itself — the matcher adds the
     * type check, nothing else.
     */
    #[Property(runs: 300)]
    public function aPatternMatcherAgreesWithPregMatch(string $subject): void
    {
        $pattern = '/^ord-[a-z0-9]+$/';

        Assert::same(
            $this->matcher(Arg::string(matches: $pattern))->matches($subject),
            preg_match($pattern, $subject) === 1,
        );
    }

    /**
     * Both accepted and rejected strings are built from the same alphabet, so
     * no Assume is needed to reach either side.
     *
     * @return array<string, ArbitraryInterface>
     */
    public static function aPatternMatcherAgreesWithPregMatchGenerators(): array
    {
        return [
            'subject' => Gen::frequency([
                [1, Gen::stringMatching('ord-[a-z0-9]{1,6}')],
                [1, Gen::stringMatching('inv-[a-z0-9]{1,6}')],
                [1, Gen::stringFrom('abcdefg0123-', 0, 10)],
            ]),
        ];
    }

    /**
     * Known cases that mutation testing and earlier bugs turned up, pinned
     * before the random phase runs.
     *
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function negationIsAnInvolutionExamples(): iterable
    {
        yield 'zero against false' => [0, false];
        yield 'empty string against zero' => ['', 0];
        yield 'null against null' => [null, null];
        yield 'numeric string against int' => ['5', 5];
    }

    private function matcher(mixed $matcher): ArgumentMatcher
    {
        \assert($matcher instanceof ArgumentMatcher);

        return $matcher;
    }
}
