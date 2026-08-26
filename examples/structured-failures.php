<?php

declare(strict_types=1);

/**
 * Reading a verification failure as data — the path a runner adapter, a
 * reporter or any other tool takes instead of parsing the message.
 *
 *   docker run --rm -v "$PWD":/app -w /app composer:2 php examples/structured-failures.php
 */

namespace Rasuvaeff\Understudy\Examples;

use Rasuvaeff\Understudy\Exception\VerificationFailed;
use Rasuvaeff\Understudy\FailureKind;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Understudy\VerificationFailure;

use function Rasuvaeff\Understudy\expect;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_check.php';

interface Shipping
{
    public function dispatch(int $orderId): void;
}

interface Invoices
{
    public function issue(int $orderId): void;
}

// --- Every failure in one report -------------------------------------------------
//
// Verification does not stop at the first problem: a test that is wrong in two
// places says so once, rather than being fixed and re-run twice.

$shipping = Understudy::for(Shipping::class);
Understudy::label($shipping, 'shipping');

$invoices = Understudy::for(Invoices::class);
Understudy::label($invoices, 'invoices');

expect(fn() => $shipping->dispatch(1))->times(2);
expect(fn() => $invoices->issue(1));

$shipping->dispatch(1);

try {
    Understudy::verifyAll();
    check(false, 'two unmet expectations were expected to fail');
} catch (VerificationFailed $failed) {
    $failures = $failed->failures();

    check(count($failures) === 2, 'both unmet expectations are in one report, not just the first');

    // The exception carries the rendered message for a human; the records carry
    // the same facts for a machine. Both are frozen public API from v0.1.0.
    foreach ($failures as $failure) {
        check($failure instanceof VerificationFailure, 'each record is a VerificationFailure value object');
        check($failure->kind === FailureKind::UnmetExpectation, 'kind: ' . $failure->kind->name);
    }

    // The order of the records is not part of the contract; the labels are.
    $byDouble = [];
    foreach ($failures as $failure) {
        $byDouble[(string) $failure->double] = $failure;
    }

    check(array_key_exists('shipping', $byDouble), 'each record names its double: ' . implode(', ', array_keys($byDouble)));

    $short = $byDouble['shipping'];
    check($short->expectation === 'dispatch(1)', 'and the call it was about: ' . (string) $short->expectation);
    check($short->expectedMinimum === 2, 'expected at least ' . (string) $short->expectedMinimum);
    check($short->expectedMaximum === 2, 'expected at most ' . (string) $short->expectedMaximum);
    check($short->actualCount === 1, 'observed ' . (string) $short->actualCount);
}

Understudy::reset();

// --- The calls that were observed --------------------------------------------------
//
// A record about calls carries them, so a report can show what happened rather
// than only what did not.

$shipping = Understudy::for(Shipping::class);
Understudy::label($shipping, 'shipping');

$shipping->dispatch(7);
$shipping->dispatch(8);

try {
    Understudy::nothingElse($shipping);
    check(false, 'the unaccounted calls were expected to be reported');
} catch (VerificationFailed $failed) {
    $failure = $failed->failures()[0];

    check($failure->kind === FailureKind::UnaccountedCalls, 'kind: ' . $failure->kind->name);
    check($failure->observedCalls !== null, 'the record carries the calls themselves');

    $ids = array_map(
        static fn(Invocation $call): int => (int) $call->args[0],
        $failure->observedCalls ?? [],
    );

    check($ids === [7, 8], 'in the order they happened: ' . implode(', ', $ids));
}

Understudy::reset();

// --- Choosing a verdict from the kind -------------------------------------------------
//
// This is what an adapter does: map a kind onto its runner's vocabulary without
// reading English.

// `match` without a default is the point: a kind added to the enum breaks this
// example, which is how the mapping gets revisited instead of silently falling
// through to something generic.
$headline = static fn(FailureKind $kind): string => match ($kind) {
    FailureKind::UnmetExpectation => 'the code never made a call the test required',
    FailureKind::StrictStubUnused => 'a stub was configured and never used',
    FailureKind::OutOfOrder => 'ordered expectations happened in the wrong order',
    FailureKind::OutOfSequence => 'the exact sequence did not match',
    FailureKind::UnaccountedCalls => 'calls happened that nothing described',
    FailureKind::UnusedDouble => 'a double was asked to receive nothing, and did not',
};

echo "\nkinds a tool can switch on:\n";

foreach (FailureKind::cases() as $kind) {
    echo '  ', str_pad($kind->name, 18), $headline($kind), "\n";
}

check(count(FailureKind::cases()) === 6, 'every kind has a verdict of its own: ' . count(FailureKind::cases()) . ' of them');
