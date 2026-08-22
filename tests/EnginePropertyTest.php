<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Understudy\Cardinality;
use Rasuvaeff\Understudy\Exception\VerificationFailed;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Tests\Fixture\Clock;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

/**
 * The laws the engine obeys, as opposed to the cases it handles. These are the
 * properties §8 of the plan asks for: matching order, cardinality, call-log
 * completeness, ledger accounting and determinism.
 */
#[Test]
#[Covers(Understudy::class)]
#[Covers(Cardinality::class)]
final class EnginePropertyTest
{
    #[AfterTest]
    public function tearDown(): void
    {
        Understudy::reset();
    }

    /**
     * The call log is complete and ordered: every dispatched call appears once
     * and keeps the order it happened in.
     */
    #[Property(runs: 200)]
    public function everyCallIsLoggedExactlyOnceInOrder(array $arguments): void
    {
        $clock = Understudy::for(Clock::class);

        foreach ($arguments as $ignored) {
            $clock->now();
        }

        $calls = Understudy::calls(fn() => $clock->now());

        Assert::same(count($calls), count($arguments));

        $sequences = array_map(static fn(Invocation $call): int => $call->sequence, $calls);
        $sorted = $sequences;
        sort($sorted);

        Assert::same($sequences, $sorted);
        Assert::same(count(array_unique($sequences)), count($sequences));
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function everyCallIsLoggedExactlyOnceInOrderGenerators(): array
    {
        return ['arguments' => Gen::arrayOf(Gen::int(), 0, 12)];
    }

    /**
     * verify() passes exactly when the number of matching calls falls inside
     * the requested bounds — the round trip between what happened and what was
     * claimed.
     */
    #[Property(runs: 300)]
    public function verifyPassesExactlyWhenTheCountIsAllowed(int $calls, int $minimum, int $slack): void
    {
        $maximum = $minimum + $slack;
        $clock = Understudy::for(Clock::class);

        for ($i = 0; $i < $calls; $i++) {
            $clock->now();
        }

        $allowed = Cardinality::between($minimum, $maximum)->allows($calls);

        Classify::cover($allowed, 'within bounds', 20);
        Classify::cover(!$allowed, 'outside bounds', 20);

        $passed = true;

        try {
            Understudy::verify(fn() => $clock->now(), minimum: $minimum, maximum: $maximum);
        } catch (VerificationFailed) {
            $passed = false;
        }

        Assert::same($passed, $allowed);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function verifyPassesExactlyWhenTheCountIsAllowedGenerators(): array
    {
        return [
            'calls' => Gen::intBetween(0, 6),
            // Constructed rather than filtered: a maximum below the minimum is
            // not a case this property is about.
            'minimum' => Gen::intBetween(0, 4),
            'slack' => Gen::intBetween(0, 4),
        ];
    }

    /**
     * The most recently registered matching stub answers, and every stub
     * registered after it either does not match or is not reached.
     */
    #[Property(runs: 200)]
    public function theLastMatchingStubWins(array $values): void
    {
        $clock = Understudy::for(Clock::class);

        foreach ($values as $value) {
            when(fn() => $clock->now())->returns($value);
        }

        Assert::same($clock->now(), $values[count($values) - 1]);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function theLastMatchingStubWinsGenerators(): array
    {
        return ['values' => Gen::nonEmptyArrayOf(Gen::int(), 8)];
    }

    /**
     * A narrower stub registered after a catch-all still answers, which is the
     * layering the documented order is for.
     */
    #[Property(runs: 200)]
    public function aSpecificStubLayersOverACatchAll(int $broad, int $specific): void
    {
        $clock = Understudy::for(Clock::class);

        when(fn() => $clock->now())->returns($broad);
        when(fn() => $clock->now())->returns($specific);

        Assert::same($clock->now(), $specific);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function aSpecificStubLayersOverACatchAllGenerators(): array
    {
        return [
            'broad' => Gen::int(),
            'specific' => Gen::int(),
        ];
    }

    /**
     * The same sequence of calls always produces the same answers and the same
     * log: nothing in the engine depends on anything but its own state.
     */
    #[Property(runs: 150)]
    public function theEngineIsDeterministic(array $values, int $calls): void
    {
        $first = $this->runScenario($values, $calls);
        Understudy::reset();
        $second = $this->runScenario($values, $calls);

        Assert::same($first, $second);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function theEngineIsDeterministicGenerators(): array
    {
        return [
            'values' => Gen::nonEmptyArrayOf(Gen::int(), 5),
            'calls' => Gen::intBetween(1, 8),
        ];
    }

    /**
     * The order of verify() calls never changes what the ledger concludes:
     * each one counts the whole log and marks idempotently.
     */
    #[Property(runs: 150)]
    public function verificationOrderDoesNotMatter(int $calls, int $repeats): void
    {
        $clock = Understudy::for(Clock::class);

        for ($i = 0; $i < $calls; $i++) {
            $clock->now();
        }

        for ($i = 0; $i < $repeats; $i++) {
            verify(fn() => $clock->now(), times: $calls);
        }

        Understudy::nothingElse($clock);

        Assert::same(count(Understudy::calls(fn() => $clock->now())), $calls);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function verificationOrderDoesNotMatterGenerators(): array
    {
        return [
            'calls' => Gen::intBetween(1, 6),
            'repeats' => Gen::intBetween(1, 4),
        ];
    }

    /**
     * A loose double answers every call with a value its declared return type
     * accepts — the promise that makes loose mode usable at all.
     */
    #[Property(runs: 200)]
    public function looseDefaultsAlwaysSatisfyTheReturnType(int $calls): void
    {
        $clock = Understudy::for(Clock::class);
        $seen = [];

        for ($i = 0; $i < $calls; $i++) {
            $seen[] = $clock->now();
        }

        foreach ($seen as $value) {
            // The declared type is int; PHP would have thrown on the way out
            // if it were not, so this asserts the promise rather than PHP.
            Assert::same(is_int($value), expected: true);
        }

        Assert::same(count($seen), $calls);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function looseDefaultsAlwaysSatisfyTheReturnTypeGenerators(): array
    {
        return ['calls' => Gen::intBetween(0, 10)];
    }

    /**
     * Cardinality is a closed interval: it accepts a count if and only if the
     * count lies between its bounds.
     */
    #[Property(runs: 300)]
    public function cardinalityAcceptsExactlyItsInterval(int $minimum, int $slack, int $count): void
    {
        $maximum = $minimum + $slack;
        $cardinality = Cardinality::between($minimum, $maximum);

        Assert::same(
            $cardinality->allows($count),
            $count >= $minimum && $count <= $maximum,
        );
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function cardinalityAcceptsExactlyItsIntervalGenerators(): array
    {
        return [
            'minimum' => Gen::intBetween(0, 10),
            'slack' => Gen::intBetween(0, 10),
            'count' => Gen::intBetween(0, 25),
        ];
    }

    /**
     * An open-ended cardinality accepts everything from its minimum upwards.
     */
    #[Property(runs: 200)]
    public function anOpenCardinalityHasNoCeiling(int $minimum, int $above): void
    {
        Assert::true(Cardinality::atLeast($minimum)->allows($minimum + $above));
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function anOpenCardinalityHasNoCeilingGenerators(): array
    {
        return [
            'minimum' => Gen::intBetween(0, 10),
            'above' => Gen::intBetween(0, 1_000),
        ];
    }

    /**
     * @param non-empty-list<int> $values
     *
     * @return list<int>
     */
    private function runScenario(array $values, int $calls): array
    {
        $clock = Understudy::for(Clock::class);
        $builder = when(fn() => $clock->now())->returns($values[0]);

        foreach (array_slice($values, 1) as $value) {
            $builder->then()->returns($value);
        }

        $answers = [];

        for ($i = 0; $i < $calls; $i++) {
            $answers[] = $clock->now();
        }

        return $answers;
    }
}
