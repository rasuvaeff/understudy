<?php

declare(strict_types=1);

/**
 * Bytes retained per live double.
 *
 * A test that builds a hundred doubles keeps them all alive until it ends, so
 * what matters is not allocation churn but what each library holds on to.
 * Every double built here is kept in an array for exactly that reason.
 *
 * The first double of a contract also pays for the generated class, which is
 * never freed; it is measured separately and reported as `first`, so the
 * per-double figure is not one library's code generation smeared over N.
 *
 * Usage:
 *
 *   php memory.php [doubles]
 */

namespace Rasuvaeff\Understudy\Perf;

use Mockery;
use Prophecy\Prophet;
use Rasuvaeff\Understudy\Perf\Support\Clock;
use Rasuvaeff\Understudy\Perf\Support\Ledger;
use Rasuvaeff\Understudy\Perf\Support\PhpUnitDoubles;
use Rasuvaeff\Understudy\Perf\Support\Warmup;
use Rasuvaeff\Understudy\Understudy;

require __DIR__ . '/vendor/autoload.php';

$count = max(10, (int) ($argv[1] ?? 500));

/**
 * @param callable(class-string): object $build
 *
 * @return array{first: int, each: float}
 */
$measure = static function (callable $build, string $contract, int $count): array {
    gc_collect_cycles();
    $before = memory_get_usage();
    $kept = [$build($contract)];
    gc_collect_cycles();
    $first = memory_get_usage() - $before;

    $before = memory_get_usage();

    for ($i = 1; $i < $count; ++$i) {
        $kept[] = $build($contract);
    }

    gc_collect_cycles();
    $each = (memory_get_usage() - $before) / ($count - 1);

    // $kept stays in scope until here on purpose: releasing it early would
    // measure what the garbage collector reclaims, not what a test holds.
    unset($kept);

    return ['first' => $first, 'each' => $each];
};

$builders = [
    'understudy' => static fn(string $contract): object => Understudy::for($contract),
    'mockery' => static fn(string $contract): object => Mockery::mock($contract),
    'prophecy' => static fn(string $contract): object => (new Prophet())->prophesize($contract)->reveal(),
    'phpunit' => static fn(string $contract): object => (new PhpUnitDoubles('bench'))->stub($contract),
];

// One double per library first: otherwise the library's own autoloading lands
// on whichever contract is measured first and swamps the figure it reports.
foreach ($builders as $builder) {
    $builder(Warmup::class);
}

printf("Memory — %d doubles per contract, PHP %s\n\n", $count, PHP_VERSION);
printf("%-12s %-8s %14s %14s\n", 'library', 'contract', 'first (KB)', 'each (bytes)');

foreach (['Clock' => Clock::class, 'Ledger' => Ledger::class] as $label => $contract) {
    foreach ($builders as $name => $builder) {
        $result = $measure($builder, $contract, $count);
        printf("%-12s %-8s %14.1f %14.0f\n", $name, $label, $result['first'] / 1024, $result['each']);
    }
}
