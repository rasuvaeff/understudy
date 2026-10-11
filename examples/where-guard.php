<?php

declare(strict_types=1);

/**
 * A constraint over several arguments together — `where()`.
 *
 *   docker run --rm -v "$PWD":/app -w /app composer:2 php examples/where-guard.php
 */

namespace Rasuvaeff\Understudy\Examples;

use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Exception\VerificationFailed;
use Rasuvaeff\Understudy\Understudy;

use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_check.php';

interface Ledger
{
    /** Moves an amount between accounts; from must not exceed to. */
    public function transfer(int $from, int $to): bool;
}

// --- where() narrows a stub by the whole argument list -------------------------
//
// The positions say "any transfer"; the guard says which ones the stub answers.

echo "when()->where()\n";

$ledger = Understudy::for(Ledger::class);
Understudy::label($ledger, 'ledger');

when(fn() => $ledger->transfer(Arg::any(), Arg::any()))
    ->where(fn(int $from, int $to) => $from <= $to, description: 'where(from <= to)')
    ->returns(true);

check($ledger->transfer(3, 7) === true, 'an ordered transfer answers the stub');

// 9 > 4: the positions matched, the joint constraint refused — the stub does
// not answer and loose mode\'s bool default does.
check($ledger->transfer(9, 4) === false, 'a refused transfer falls to the default');

Understudy::reset();

// --- verify() counts only the calls the guard accepted --------------------------

echo "verify(where:)\n";

$ledger = Understudy::for(Ledger::class);
Understudy::label($ledger, 'ledger');

$ledger->transfer(9, 4);
$ledger->transfer(2, 5);
$ledger->transfer(1, 6);

verify(
    fn() => $ledger->transfer(Arg::any(), Arg::any()),
    times: 2,
    where: fn(int $from, int $to) => $from <= $to,
);

check(true, 'two of the three calls satisfied the joint constraint');

// And the refusal names the guard — verify() renders it with its default
// description, which is what a reader of the report sees:
try {
    verify(
        fn() => $ledger->transfer(Arg::any(), Arg::any()),
        times: 5,
        where: fn(int $from, int $to) => $from <= $to,
    );
} catch (VerificationFailed $failure) {
    $message = $failure->getMessage();

    check(str_contains($message, 'where(…)'), 'the failure renders the guard');
}

Understudy::reset();
