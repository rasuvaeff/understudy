<?php

declare(strict_types=1);

/**
 * One process, one double. Started by `cold-start.php`, never on its own.
 *
 * Everything a library does once per process — autoloading its own classes,
 * generating the double's class, priming whatever registry it keeps — lands in
 * this process's wall time. That is the number a test suite pays per worker
 * process, and no in-process benchmark can see it.
 */

namespace Rasuvaeff\Understudy\Perf;

use Mockery;
use PHPUnit\Framework\MockObject\Stub;
use Prophecy\Prophet;
use Rasuvaeff\Understudy\Perf\Support\Clock;
use Rasuvaeff\Understudy\Perf\Support\PhpUnitDoubles;
use Rasuvaeff\Understudy\Understudy;

use function Rasuvaeff\Understudy\when;

require __DIR__ . '/vendor/autoload.php';

$library = $argv[1] ?? 'baseline';

$result = match ($library) {
    // Composer's autoloader and this file, and nothing else: subtract it from
    // the others to read the library's own share.
    'baseline' => 0,
    'understudy' => (static function (): int {
        $clock = Understudy::for(Clock::class);
        when(static fn(): int => $clock->now())->returns(1_700_000_000);

        return $clock->now();
    })(),
    'mockery' => (static function (): int {
        $clock = Mockery::mock(Clock::class);
        $clock->shouldReceive('now')->andReturn(1_700_000_000);

        return $clock->now();
    })(),
    'prophecy' => (static function (): int {
        $prophecy = (new Prophet())->prophesize(Clock::class);
        $prophecy->now()->willReturn(1_700_000_000);
        /** @var Clock $clock */
        $clock = $prophecy->reveal();

        return $clock->now();
    })(),
    'phpunit' => (static function (): int {
        /** @var Clock&Stub $clock */
        $clock = (new PhpUnitDoubles('bench'))->stub(Clock::class);
        $clock->method('now')->willReturn(1_700_000_000);

        return $clock->now();
    })(),
    default => throw new \InvalidArgumentException(sprintf('Unknown library "%s"', $library)),
};

// Printed so a silently broken worker cannot pass for a fast one.
echo $result, "\n";
