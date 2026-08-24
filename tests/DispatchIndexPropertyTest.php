<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Runtime\DoubleState;
use Rasuvaeff\Understudy\Tests\Fixture\Tally;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

use function Rasuvaeff\Understudy\when;

/**
 * The dispatcher does not walk every expectation any more: those keyed by a
 * literal first argument live in an index, the rest in a list, and a call
 * merges whichever of the two can answer it.
 *
 * That is an optimisation, and an optimisation of a lookup is exactly where a
 * subtle ordering bug hides — the wrong stub answering is silent, and a suite
 * built on it stays green. So the property is stated against the thing the
 * index replaced: whatever a plain most-recent-first walk would have
 * answered, the index answers too.
 *
 * @internal
 */
#[Test]
#[Covers(DoubleState::class)]
final class DispatchIndexPropertyTest
{
    /** Literal `0..4`, range `5..9`, catch-all `10..12`. */
    private const int LITERAL_MAX = 4;

    private const int RANGE_MAX = 9;

    #[AfterTest]
    public function tearDown(): void
    {
        Understudy::reset();
    }

    /**
     * @param list<int> $encoded
     */
    #[Property(runs: 300, timeoutMs: 10_000)]
    public function theIndexAnswersWhatALinearWalkWouldHave(array $encoded, int $call): void
    {
        Classify::cover($encoded === [], 'no expectations', 3.0);
        Classify::cover(count($encoded) > 5, 'many expectations', 15.0);
        Classify::cover(
            array_filter($encoded, static fn(int $c): bool => $c <= self::LITERAL_MAX) !== [],
            'a literal stub',
            30.0,
        );
        Classify::cover(
            array_filter($encoded, static fn(int $c): bool => $c > self::RANGE_MAX) !== [],
            'a catch-all stub',
            30.0,
        );
        Classify::cover($this->modelAnswer($encoded, $call) === null, 'nothing matched', 5.0);

        $double = Understudy::for(Tally::class);

        foreach ($encoded as $position => $code) {
            $argument = $this->argumentFor($code);

            when(static fn() => $double->score($argument))->returns($position);
        }

        Assert::same($double->score($call), $this->modelAnswer($encoded, $call) ?? 0);
    }

    /**
     * Cases the random phase would reach only by luck, and each of which
     * ordering bugs actually produce: a literal shadowed by a later catch-all,
     * a catch-all shadowed by a later literal, and the same literal twice.
     */
    public static function theIndexAnswersWhatALinearWalkWouldHaveExamples(): iterable
    {
        yield 'a later catch-all shadows an earlier literal' => [[2, 12], 2];
        yield 'a later literal shadows an earlier catch-all' => [[12, 2], 2];
        yield 'the same literal twice, the later one answers' => [[2, 2], 2];
        yield 'a range shadowed by a literal outside it' => [[5, 3], 6];
        yield 'nothing matches the call' => [[0, 1], 4];
        yield 'no expectations at all' => [[], 3];
    }

    /** @return array<string, ArbitraryInterface> */
    public static function theIndexAnswersWhatALinearWalkWouldHaveGenerators(): array
    {
        return [
            'encoded' => Gen::arrayOf(Gen::intBetween(0, 12), 0, 8),
            'call' => Gen::intBetween(0, 6),
        ];
    }

    /**
     * What the dispatcher did before the index existed: the most recently
     * registered expectation that matches, and no other.
     *
     * @param list<int> $encoded
     */
    private function modelAnswer(array $encoded, int $call): ?int
    {
        for ($position = count($encoded) - 1; $position >= 0; --$position) {
            if ($this->codeMatches($encoded[$position], $call)) {
                return $position;
            }
        }

        return null;
    }

    private function codeMatches(int $code, int $call): bool
    {
        return match (true) {
            $code <= self::LITERAL_MAX => $call === $code,
            $code <= self::RANGE_MAX => $call >= $code - 5 && $call <= $code - 5 + 2,
            default => true,
        };
    }

    private function argumentFor(int $code): mixed
    {
        return match (true) {
            $code <= self::LITERAL_MAX => $code,
            $code <= self::RANGE_MAX => Arg::int(min: $code - 5, max: $code - 5 + 2),
            default => Arg::any(),
        };
    }
}
