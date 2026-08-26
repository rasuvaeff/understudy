<?php

declare(strict_types=1);

/**
 * The three modes a double can be in, and what each one answers.
 *
 *   docker run --rm -v "$PWD":/app -w /app composer:2 php examples/modes.php
 */

namespace Rasuvaeff\Understudy\Examples;

use Rasuvaeff\Understudy\Exception\StrictModeViolation;
use Rasuvaeff\Understudy\Understudy;

use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_check.php';

interface Clock
{
    public function now(): int;

    public function zone(): string;
}

final class FixedClock implements Clock
{
    public function now(): int
    {
        return 1_700_000_000;
    }

    public function zone(): string
    {
        return 'UTC';
    }
}

// --- Loose: the default ------------------------------------------------------
//
// Anything not configured answers with a type-safe default. Nothing fails, and
// the test says what it cares about and nothing more.

echo "loose\n";

$loose = Understudy::for(Clock::class);

when(fn() => $loose->now())->returns(42);

check($loose->now() === 42, 'a configured call answers from the specification');
check($loose->zone() === '', 'anything else answers with a default of the declared type');

Understudy::reset();

// --- Strict: no unconfigured call ---------------------------------------------
//
// The opposite policy: a call nobody described is a mistake, and it is reported
// at the moment it happens rather than at the end of the test.

echo "\nstrict\n";

$strict = Understudy::for(Clock::class);
Understudy::label($strict, 'clock');
Understudy::strict($strict);

when(fn() => $strict->now())->returns(7);

check($strict->now() === 7, 'a configured call answers as usual under strict mode');

try {
    $strict->zone();
    check(false, 'an unconfigured call was expected to be refused');
} catch (StrictModeViolation $violation) {
    check(
        str_contains($violation->getMessage(), 'zone()'),
        'an unconfigured call is refused, and the refusal names it: ' . explode("\n", $violation->getMessage())[0],
    );
}

Understudy::reset();

// --- Forwarding: a real object behind the double ------------------------------
//
// Everything the test did not configure runs for real and is recorded. This is
// the partial double: pick the one call you need to control, leave the rest
// alone.

echo "\nforwarding\n";

$real = new FixedClock();
$spy = Understudy::for(Clock::class);
Understudy::forwarding($spy, $real);

check($spy->zone() === 'UTC', 'an unconfigured call reaches the real object');

when(fn() => $spy->now())->throws(new \RuntimeException('clock unavailable'));

try {
    $spy->now();
    check(false, 'the configured call was expected to throw');
} catch (\RuntimeException $failure) {
    check($failure->getMessage() === 'clock unavailable', 'a configured call answers instead of the real object');
}

// Forwarded calls are recorded like any other, so the double is still a spy.
verify(fn() => $spy->zone());
check(true, 'a forwarded call is in the call log, so it can be verified');

Understudy::reset();
