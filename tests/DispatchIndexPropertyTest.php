<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Runtime\DoubleState;
use Rasuvaeff\Understudy\Tests\Fixture\Ledger;
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
     * The literals the index has to tell apart, and the pairs `===` calls
     * equal while their serialisations differ or the other way round.
     *
     * `-0.0` and `0.0` are one value to `===` and two to `serialize()`, which
     * is what the key used to be: a stub armed with one was invisible to a
     * call made with the other, but only once a second expectation brought
     * the index into play — so an unrelated stub changed the first one's
     * behaviour. `NAN` is the opposite: one key for values `===` calls
     * distinct, which is harmless because the candidates the index returns
     * are still compared with `===`.
     *
     * The rest are the pairs a looser key would conflate: `0` against `0.0`
     * and `'0'`, `''` against `null` and `false`, `1` against `true`.
     */
    private const array LITERALS = [
        -0.0, 0.0, 0, '0', '', null, false, true, 1, 1.0, NAN, 'a',
    ];

    /**
     * The same claim as above for a first argument of any scalar type: the
     * index answers whatever a most-recent-first walk comparing with `===`
     * would have.
     *
     * A separate property rather than a wider alphabet in the one above,
     * because that one is about ORDER — literals shadowing ranges shadowing
     * catch-alls — and this one is about IDENTITY. Mixing them would leave
     * both under-covered in the same number of runs.
     *
     * @param list<int> $armed indexes into {@see LITERALS}
     */
    #[Property(runs: 300, timeoutMs: 10_000)]
    public function theIndexAgreesWithIdentityForAnyLiteral(array $armed, int $called): void
    {
        Classify::cover($armed === [], 'no expectations', 2.0);
        Classify::cover(count($armed) > 1, 'the index is consulted at all', 40.0);
        Classify::cover($this->identityAnswer($armed, $called) === null, 'nothing matched', 10.0);

        $double = Understudy::for(Ledger::class);

        foreach ($armed as $position => $index) {
            $argument = self::LITERALS[$index];

            when(static fn() => $double->at($argument))->returns($position);
        }

        Assert::same($double->at(self::LITERALS[$called]), $this->identityAnswer($armed, $called) ?? 0);
    }

    /**
     * The pairs that made the key wrong, and the neighbours a fix could break
     * by widening it. None of these is likely to be drawn twice in one run by
     * chance, and each is a bug somebody has shipped.
     */
    public static function theIndexAgreesWithIdentityForAnyLiteralExamples(): iterable
    {
        // The bug: `-0.0` armed, `0.0` called, with a second expectation to
        // make the index matter at all.
        yield 'a negative zero answers a positive one' => [[0, 11], 1];
        yield 'a positive zero answers a negative one' => [[1, 11], 0];
        // The neighbours: distinct values a looser key would conflate.
        yield 'an int zero is not a float zero' => [[2, 11], 1];
        yield 'a string zero is not an int zero' => [[3, 11], 2];
        yield 'an empty string is not null' => [[4, 11], 5];
        yield 'false is not null' => [[6, 11], 5];
        yield 'true is not one' => [[7, 11], 8];
        yield 'an int one is not a float one' => [[8, 11], 9];
        // NAN is equal to nothing, itself included.
        yield 'NAN matches no call, not even NAN' => [[10, 11], 10];
        yield 'a single expectation skips the index entirely' => [[0], 1];
    }

    /** @return array<string, ArbitraryInterface> */
    public static function theIndexAgreesWithIdentityForAnyLiteralGenerators(): array
    {
        $last = count(self::LITERALS) - 1;

        return [
            'armed' => Gen::arrayOf(Gen::intBetween(0, $last), 0, 6),
            'called' => Gen::intBetween(0, $last),
        ];
    }

    /**
     * The most recently registered expectation whose literal IS the call's,
     * by the same `===` the dispatcher applies to its candidates.
     *
     * @param list<int> $armed
     */
    private function identityAnswer(array $armed, int $called): ?int
    {
        for ($position = count($armed) - 1; $position >= 0; --$position) {
            if (self::LITERALS[$armed[$position]] === self::LITERALS[$called]) {
                return $position;
            }
        }

        return null;
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
