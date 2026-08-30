<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Rasuvaeff\Understudy\Understudy;

use function Rasuvaeff\Understudy\when;

interface AttachmentStore
{
    /** @return resource */
    public function open(string $path);
}

// A double that answers with a real stream. Nothing here is unusual — a
// forwarding double over a real filesystem store does exactly this.
$store = Understudy::for(AttachmentStore::class);
when(static fn () => $store->open('report.csv'))->answers(
    static fn () => fopen('php://temp', 'rb+'),
);

$handle = $store->open('report.csv');
unset($handle);

// The call log still holds the stream: the value the double returned is
// retained until reset(), which under a runner adapter happens AFTER teardown.
$call = Understudy::lastCall(static fn () => $store->open('report.csv'));
echo 'retained: ', get_debug_type($call?->returned()), "\n";

// Built lean, the same double keeps the call and drops the value.
Understudy::reset();
$lean = Understudy::for(AttachmentStore::class);
Understudy::lean($lean);
when(static fn () => $lean->open('report.csv'))->answers(
    static fn () => fopen('php://temp', 'rb+'),
);

$lean->open('report.csv');

echo 'transcript: ', trim(Understudy::transcript($lean)), "\n\n";

$leanCall = Understudy::lastCall(static fn () => $lean->open('report.csv'));
show(static fn () => $leanCall?->returned());
