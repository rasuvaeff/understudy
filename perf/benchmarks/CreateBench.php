<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Perf;

use Mockery;
use Prophecy\Prophet;
use Rasuvaeff\Understudy\Perf\Support\Clock;
use Rasuvaeff\Understudy\Perf\Support\Ledger;
use Rasuvaeff\Understudy\Perf\Support\PhpUnitDoubles;
use Rasuvaeff\Understudy\Understudy;
use Testo\Bench;

/**
 * Building a double, as one test would: scope in, double out, scope torn down.
 *
 * The teardown is not padding. Understudy and Mockery keep their bookkeeping in
 * process-global state that a test must clear (`reset()`, `Mockery::close()`),
 * while Prophecy hangs it on a `Prophet` and PHPUnit on the `TestCase` — objects
 * this benchmark therefore constructs per iteration. Leaving the teardown out
 * would measure two libraries mid-leak and the other two clean.
 *
 * Every library generates a class the first time it sees a contract and reuses
 * it afterwards, so this is the warm path: instantiation and bookkeeping, not
 * code generation. Cold generation needs a fresh process each time — that is
 * `cold-start.php`.
 *
 * Two contracts, because the cost of a double is not one number: `Clock` has a
 * single method, `Ledger` has eight, with nullable, union, array and defaulted
 * types. The gap between them is the part that scales with the contract.
 */
final class CreateBench
{
    // --- One method ---------------------------------------------------------

    #[Bench(
        [
            'mockery' => [self::class, 'narrowMockery'],
            'prophecy' => [self::class, 'narrowProphecy'],
            'phpunit mock' => [self::class, 'narrowPhpUnitMock'],
            'phpunit stub' => [self::class, 'narrowPhpUnitStub'],
        ],
        warmup: 500,
        calls: 2_000,
        iterations: 25,
    )]
    public static function narrowUnderstudy(): void
    {
        Understudy::for(Clock::class);
        Understudy::reset();
    }

    public static function narrowMockery(): void
    {
        Mockery::mock(Clock::class);
        Mockery::close();
    }

    public static function narrowProphecy(): void
    {
        (new Prophet())->prophesize(Clock::class)->reveal();
    }

    public static function narrowPhpUnitMock(): void
    {
        (new PhpUnitDoubles('bench'))->mock(Clock::class);
    }

    public static function narrowPhpUnitStub(): void
    {
        (new PhpUnitDoubles('bench'))->stub(Clock::class);
    }

    // --- Eight methods ------------------------------------------------------

    #[Bench(
        [
            'mockery' => [self::class, 'wideMockery'],
            'prophecy' => [self::class, 'wideProphecy'],
            'phpunit mock' => [self::class, 'widePhpUnitMock'],
            'phpunit stub' => [self::class, 'widePhpUnitStub'],
        ],
        warmup: 500,
        calls: 2_000,
        iterations: 25,
    )]
    public static function wideUnderstudy(): void
    {
        Understudy::for(Ledger::class);
        Understudy::reset();
    }

    public static function wideMockery(): void
    {
        Mockery::mock(Ledger::class);
        Mockery::close();
    }

    public static function wideProphecy(): void
    {
        (new Prophet())->prophesize(Ledger::class)->reveal();
    }

    public static function widePhpUnitMock(): void
    {
        (new PhpUnitDoubles('bench'))->mock(Ledger::class);
    }

    public static function widePhpUnitStub(): void
    {
        (new PhpUnitDoubles('bench'))->stub(Ledger::class);
    }
}
