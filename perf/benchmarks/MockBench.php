<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Perf;

use Mockery;
use PHPUnit\Framework\MockObject\MockObject;
use Prophecy\Argument;
use Prophecy\Prophet;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Perf\Support\Clock;
use Rasuvaeff\Understudy\Perf\Support\Ledger;
use Rasuvaeff\Understudy\Perf\Support\PhpUnitDoubles;
use Rasuvaeff\Understudy\Understudy;
use Testo\Bench;

use function Rasuvaeff\Understudy\expect;

/**
 * A mock: an expectation stated up front and checked at the end.
 *
 * The verification is inside the measured unit on purpose. A library that
 * defers its bookkeeping to `verify()` looks fast until the moment somebody
 * calls it, and every test does.
 *
 * The second scenario adds an argument matcher, because matching is where the
 * per-call work of a strict double actually happens.
 */
final class MockBench
{
    // --- Expect, call, verify -----------------------------------------------

    #[Bench(
        [
            'mockery' => [self::class, 'verifiedMockery'],
            'prophecy' => [self::class, 'verifiedProphecy'],
            'phpunit mock' => [self::class, 'verifiedPhpUnit'],
        ],
        warmup: 500,
        calls: 2_000,
        iterations: 25,
    )]
    public static function verifiedUnderstudy(): void
    {
        $clock = Understudy::for(Clock::class);
        expect(static fn(): int => $clock->now())->times(1)->returns(1_700_000_000);

        $clock->now();

        Understudy::verifyAll();
        Understudy::reset();
    }

    public static function verifiedMockery(): void
    {
        $clock = Mockery::mock(Clock::class);
        $clock->shouldReceive('now')->once()->andReturn(1_700_000_000);

        $clock->now();

        Mockery::close();
    }

    public static function verifiedProphecy(): void
    {
        $prophet = new Prophet();
        $prophecy = $prophet->prophesize(Clock::class);
        $prophecy->now()->shouldBeCalledOnce()->willReturn(1_700_000_000);
        /** @var Clock $clock */
        $clock = $prophecy->reveal();

        $clock->now();

        $prophet->checkPredictions();
    }

    public static function verifiedPhpUnit(): void
    {
        $case = new PhpUnitDoubles('bench');
        /** @var Clock&MockObject $clock */
        $clock = $case->mock(Clock::class);
        $clock->expects($case->exactlyOnce())->method('now')->willReturn(1_700_000_000);

        $clock->now();

        $clock->__phpunit_verify(false);
    }

    // --- With an argument matcher -------------------------------------------

    #[Bench(
        [
            'mockery' => [self::class, 'matchedMockery'],
            'prophecy' => [self::class, 'matchedProphecy'],
        ],
        warmup: 500,
        calls: 2_000,
        iterations: 25,
    )]
    public static function matchedUnderstudy(): void
    {
        $ledger = Understudy::for(Ledger::class);
        expect(static fn(): bool => $ledger->rename('draft', Arg::any()))
            ->times(1)
            ->returns(true);

        $ledger->rename('draft', 'final');

        Understudy::verifyAll();
        Understudy::reset();
    }

    public static function matchedMockery(): void
    {
        $ledger = Mockery::mock(Ledger::class);
        $ledger->shouldReceive('rename')->once()->with('draft', Mockery::any())->andReturn(true);

        $ledger->rename('draft', 'final');

        Mockery::close();
    }

    public static function matchedProphecy(): void
    {
        $prophet = new Prophet();
        $prophecy = $prophet->prophesize(Ledger::class);
        $prophecy->rename('draft', Argument::any())->shouldBeCalledOnce()->willReturn(true);
        /** @var Ledger $ledger */
        $ledger = $prophecy->reveal();

        $ledger->rename('draft', 'final');

        $prophet->checkPredictions();
    }

    /*
     * PHPUnit is absent from this table, and not because it lost.
     * `->with(...)` verification runs through
     * `MockObject\Rule\Parameters::doVerify()`, which increments the assertion
     * count of the test case it finds by walking the call stack — outside a
     * PHPUnit process there is none, and it throws
     * `NoTestCaseObjectOnCallStackException`. Its argument matching cannot be
     * measured from this harness at all; that would need a PHPUnit-native one.
     */
}
