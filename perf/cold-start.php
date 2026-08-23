<?php

declare(strict_types=1);

/**
 * Cold start: how long a fresh PHP process takes to produce its first working
 * double.
 *
 * The in-process benchmarks all measure the warm path — by their second call
 * every library here is reusing a class it already generated. This one cannot
 * be folded into them, because nothing unloads a declared class: the only way
 * to pay code generation again is a new process.
 *
 * Usage:
 *
 *   php cold-start.php [runs]
 */

namespace Rasuvaeff\Understudy\Perf;

require __DIR__ . '/vendor/autoload.php';

$runs = max(3, (int) ($argv[1] ?? 15));
$libraries = ['baseline', 'understudy', 'mockery', 'prophecy', 'phpunit'];
$php = PHP_BINARY;
$worker = __DIR__ . '/cold-worker.php';

/** @var array<string, list<float>> $samples */
$samples = [];

foreach ($libraries as $library) {
    for ($run = 0; $run < $runs; ++$run) {
        $started = hrtime(true);
        exec(sprintf('%s %s %s 2>&1', escapeshellarg($php), escapeshellarg($worker), escapeshellarg($library)), $output, $status);
        $elapsed = (hrtime(true) - $started) / 1e6;

        if ($status !== 0) {
            fwrite(STDERR, sprintf("worker for %s exited with %d: %s\n", $library, $status, implode("\n", $output)));

            exit(1);
        }

        $output = [];
        $samples[$library][] = $elapsed;
    }
}

$median = static function (array $values): float {
    sort($values);
    $middle = intdiv(count($values), 2);

    return count($values) % 2 === 0
        ? ($values[$middle - 1] + $values[$middle]) / 2
        : $values[$middle];
};

$baseline = $median($samples['baseline']);

printf("Cold start — %d processes per library, medians in ms\n", $runs);
printf("PHP %s, %s\n\n", PHP_VERSION, php_uname('s') . ' ' . php_uname('m'));
printf("%-12s %10s %10s %10s\n", 'library', 'total', 'minus base', 'vs fastest');

$net = [];

foreach ($libraries as $library) {
    if ($library === 'baseline') {
        continue;
    }

    $net[$library] = $median($samples[$library]) - $baseline;
}

$fastest = min($net);

printf("%-12s %9.2f %10s %10s\n", 'baseline', $baseline, '—', '—');

foreach ($net as $library => $value) {
    printf(
        "%-12s %9.2f %9.2f %9s\n",
        $library,
        $median($samples[$library]),
        $value,
        $value === $fastest ? '1.00x' : sprintf('%.2fx', $value / $fastest),
    );
}
