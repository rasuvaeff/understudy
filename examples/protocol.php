<?php

declare(strict_types=1);

/**
 * Saying what the protocol was, not just which calls happened: order,
 * exactness, and "nothing else".
 *
 *   docker run --rm -v "$PWD":/app -w /app composer:2 php examples/protocol.php
 */

namespace Rasuvaeff\Understudy\Examples;

use Rasuvaeff\Understudy\Exception\VerificationFailed;
use Rasuvaeff\Understudy\FailureKind;
use Rasuvaeff\Understudy\Understudy;

use function Rasuvaeff\Understudy\expect;
use function Rasuvaeff\Understudy\expectSequence;
use function Rasuvaeff\Understudy\verify;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_check.php';

interface Connection
{
    public function begin(): void;

    public function write(string $row): void;

    public function commit(): void;
}

interface Metrics
{
    public function increment(string $name): void;
}

// --- ordered(): these expectations, in this order ------------------------------
//
// Unrelated calls may happen in between. What is constrained is the ordered
// expectations relative to each other.

echo "expect()->ordered()\n";

$db = Understudy::for(Connection::class);
Understudy::label($db, 'db');

expect(fn() => $db->begin())->ordered();
expect(fn() => $db->commit())->ordered();

$db->begin();
$db->write('a row nobody described');
$db->commit();

Understudy::verifyAll();
check(true, 'begin() before commit(), with an unrelated call in between');

Understudy::reset();

// The same protocol backwards is a failure, and the failure says which kind.

$db = Understudy::for(Connection::class);
Understudy::label($db, 'db');

expect(fn() => $db->begin())->ordered();
expect(fn() => $db->commit())->ordered();

$db->commit();
$db->begin();

try {
    Understudy::verifyAll();
    check(false, 'the reversed protocol was expected to fail');
} catch (VerificationFailed $failed) {
    $kinds = array_map(static fn(object $failure): FailureKind => $failure->kind, $failed->failures());
    check(in_array(FailureKind::OutOfOrder, $kinds, true), 'the reversed protocol fails as OutOfOrder');
}

Understudy::reset();

// --- verifySequence(): the whole protocol, across doubles -----------------------
//
// It compares the double identity as well as the method and arguments, so two
// doubles of the same contract cannot stand in for each other.

echo "\nverifySequence()\n";

$db = Understudy::for(Connection::class);
$metrics = Understudy::for(Metrics::class);

$db->begin();
$db->write('one');
$metrics->increment('rows');
$db->commit();

Understudy::verifySequence(
    fn() => $db->begin(),
    fn() => $db->write('one'),
    fn() => $metrics->increment('rows'),
    fn() => $db->commit(),
);
check(true, 'the exact call sequence, spanning two doubles');

Understudy::reset();

// --- nothingElse(): everything was accounted for --------------------------------
//
// A call counts as accounted for when an expect() matched it or a SUCCESSFUL
// verify() claimed it. A when() stub accounts for nothing — it is permission,
// not a description of what happened.

echo "\nnothingElse()\n";

$db = Understudy::for(Connection::class);
Understudy::label($db, 'db');

$db->begin();
$db->write('one');

verify(fn() => $db->begin());
verify(fn() => $db->write('one'));

Understudy::nothingElse($db);
check(true, 'every call was claimed by a verification');

$db->write('a row nobody claimed');

try {
    Understudy::nothingElse($db);
    check(false, 'the unclaimed call was expected to be reported');
} catch (VerificationFailed $failed) {
    $failure = $failed->failures()[0];
    check($failure->kind === FailureKind::UnaccountedCalls, 'an unclaimed call is UnaccountedCalls');
    check($failure->actualCount === 1, 'and the report counts it: ' . $failure->actualCount);
}

Understudy::reset();

// Everything above answers in teardown. expectSequence() arms the protocol
// before the subject runs, so the refusal happens inside the call that broke
// the order — which is where the stack trace is worth reading.

echo "\nexpectSequence()\n";

$db = Understudy::for(Connection::class);
Understudy::label($db, 'db');

expectSequence(
    fn() => $db->begin(),
    fn() => $db->write('one'),
);

$db->begin();

try {
    // `begin()` again: a step the protocol has already moved past is still a
    // protocol call, and it is arriving at the wrong moment.
    $db->begin();
    check(false, 'the repeated step was expected to be refused at the call');
} catch (VerificationFailed $failed) {
    $failure = $failed->failures()[0];
    check($failure->kind === FailureKind::OutOfSequence, 'a call out of turn is OutOfSequence');
    check(
        str_contains($failure->summary, 'step 2 of 2'),
        'and the report names the step that was due',
    );
}

// Arming is a claim as well as a guard: a subject that never reaches the last
// step fails in teardown, which is what still catches a subject whose broad
// `catch` swallowed the refusal above.
try {
    Understudy::verifyAll();
    check(false, 'the unfinished protocol was expected to be reported');
} catch (VerificationFailed $failed) {
    check(
        str_contains($failed->getMessage(), 'stopped at step 2 of 2'),
        'an unfinished protocol is reported by verifyAll()',
    );
}

Understudy::reset();
