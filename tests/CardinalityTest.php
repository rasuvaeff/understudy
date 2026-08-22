<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\Understudy\Cardinality;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(Cardinality::class)]
final class CardinalityTest
{
    #[DataProvider('allowsProvider')]
    public function decidesWhetherACountIsAllowed(Cardinality $cardinality, int $count, bool $expected): void
    {
        Assert::same($cardinality->allows($count), $expected);
    }

    /**
     * @return iterable<string, array{Cardinality, int, bool}>
     */
    public static function allowsProvider(): iterable
    {
        yield 'exactly accepts the count' => [Cardinality::exactly(2), 2, true];
        yield 'exactly rejects one fewer' => [Cardinality::exactly(2), 1, false];
        yield 'exactly rejects one more' => [Cardinality::exactly(2), 3, false];
        yield 'exactly zero accepts none' => [Cardinality::exactly(0), 0, true];

        yield 'atLeast accepts the bound' => [Cardinality::atLeast(2), 2, true];
        yield 'atLeast accepts more' => [Cardinality::atLeast(2), 99, true];
        yield 'atLeast rejects fewer' => [Cardinality::atLeast(2), 1, false];

        yield 'between accepts the lower bound' => [Cardinality::between(1, 3), 1, true];
        yield 'between accepts the upper bound' => [Cardinality::between(1, 3), 3, true];
        yield 'between rejects below' => [Cardinality::between(1, 3), 0, false];
        yield 'between rejects above' => [Cardinality::between(1, 3), 4, false];
        yield 'between without a maximum is unbounded' => [Cardinality::between(1, null), 99, true];

        yield 'any accepts none' => [Cardinality::any(), 0, true];
        yield 'any accepts many' => [Cardinality::any(), 99, true];

        yield 'never accepts none' => [Cardinality::never(), 0, true];
        yield 'never rejects one' => [Cardinality::never(), 1, false];
    }

    #[DataProvider('describeProvider')]
    public function readsBackAsEnglish(Cardinality $cardinality, string $expected): void
    {
        Assert::same($cardinality->describe(), $expected);
    }

    /**
     * @return iterable<string, array{Cardinality, string}>
     */
    public static function describeProvider(): iterable
    {
        yield 'never' => [Cardinality::never(), 'never'];
        yield 'any' => [Cardinality::any(), 'any number of times'];
        yield 'exactly one' => [Cardinality::exactly(1), 'exactly 1 time'];
        yield 'exactly many' => [Cardinality::exactly(3), 'exactly 3 times'];
        yield 'at least one' => [Cardinality::atLeast(1), 'at least 1 time'];
        yield 'at least many' => [Cardinality::atLeast(2), 'at least 2 times'];
        yield 'between' => [Cardinality::between(1, 3), 'between 1 and 3 times'];
        yield 'zero to one' => [Cardinality::between(0, 1), 'between 0 and 1 time'];
    }

    public function aNegativeMinimumIsRejected(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('cannot be negative');

        Cardinality::exactly(-1);
    }

    public function aMaximumBelowTheMinimumIsRejected(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('below the minimum');

        Cardinality::between(3, 1);
    }

    public function boundsAreReadable(): void
    {
        $cardinality = Cardinality::between(1, 3);

        Assert::same($cardinality->minimum, 1);
        Assert::same($cardinality->maximum, 3);
        Assert::null(Cardinality::atLeast(1)->maximum);
    }
}
