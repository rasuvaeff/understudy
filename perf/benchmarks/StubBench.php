<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Perf;

use Mockery;
use PHPUnit\Framework\MockObject\Stub;
use Prophecy\Prophet;
use Rasuvaeff\Understudy\Perf\Support\Clock;
use Rasuvaeff\Understudy\Perf\Support\PhpUnitDoubles;
use Rasuvaeff\Understudy\Understudy;
use Testo\Bench;

use function Rasuvaeff\Understudy\when;

/**
 * A stub: canned answer in, value out, no verification.
 *
 * Both scenarios time a whole test — build, stub, call, tear down — and differ
 * only in how many times the stubbed method is called. That is deliberate: a
 * benchmark that creates one double and then calls it a hundred thousand times
 * measures a call history nobody accumulates, and every library here grows one.
 * Reading the marginal cost of a call as `(twentyCalls − oneCall) / 19` keeps
 * the history the size a real test would have.
 */
final class StubBench
{
    private const int REPEATS = 20;

    // --- One call -----------------------------------------------------------

    #[Bench(
        [
            'mockery' => [self::class, 'onceMockery'],
            'prophecy' => [self::class, 'onceProphecy'],
            'phpunit stub' => [self::class, 'oncePhpUnit'],
        ],
        warmup: 200,
        calls: 50,
        iterations: 200,
        tolerance: \INF,
    )]
    public static function onceUnderstudy(): void
    {
        self::understudy(1);
    }

    public static function onceMockery(): void
    {
        self::mockery(1);
    }

    public static function onceProphecy(): void
    {
        self::prophecy(1);
    }

    public static function oncePhpUnit(): void
    {
        self::phpunit(1);
    }

    // --- Twenty calls -------------------------------------------------------

    #[Bench(
        [
            'mockery' => [self::class, 'manyMockery'],
            'prophecy' => [self::class, 'manyProphecy'],
            'phpunit stub' => [self::class, 'manyPhpUnit'],
        ],
        warmup: 200,
        calls: 50,
        iterations: 200,
        tolerance: \INF,
    )]
    public static function manyUnderstudy(): void
    {
        self::understudy(self::REPEATS);
    }

    public static function manyMockery(): void
    {
        self::mockery(self::REPEATS);
    }

    public static function manyProphecy(): void
    {
        self::prophecy(self::REPEATS);
    }

    public static function manyPhpUnit(): void
    {
        self::phpunit(self::REPEATS);
    }

    // --- The four implementations -------------------------------------------

    private static function understudy(int $calls): void
    {
        $clock = Understudy::for(Clock::class);
        when(static fn(): int => $clock->now())->returns(1_700_000_000);

        for ($i = 0; $i < $calls; ++$i) {
            $clock->now();
        }

        Understudy::reset();
    }

    private static function mockery(int $calls): void
    {
        $clock = Mockery::mock(Clock::class);
        $clock->shouldReceive('now')->andReturn(1_700_000_000);

        for ($i = 0; $i < $calls; ++$i) {
            $clock->now();
        }

        Mockery::close();
    }

    private static function prophecy(int $calls): void
    {
        $prophecy = (new Prophet())->prophesize(Clock::class);
        $prophecy->now()->willReturn(1_700_000_000);
        /** @var Clock $clock */
        $clock = $prophecy->reveal();

        for ($i = 0; $i < $calls; ++$i) {
            $clock->now();
        }
    }

    private static function phpunit(int $calls): void
    {
        /** @var Clock&Stub $clock */
        $clock = (new PhpUnitDoubles('bench'))->stub(Clock::class);
        $clock->method('now')->willReturn(1_700_000_000);

        for ($i = 0; $i < $calls; ++$i) {
            $clock->now();
        }
    }
}
