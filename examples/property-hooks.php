<?php

declare(strict_types=1);

/**
 * Doubling a contract that declares properties (PHP 8.4+): reads answer the
 * mode's type-safe default, a `{ get; set; }` property round-trips what the
 * code under test wrote, and a get-only property refuses a write with PHP's
 * own error.
 *
 *   docker run --rm -v "$PWD":/app -w /app composer:2 php examples/property-hooks.php
 *
 * The contracts are built by eval on purpose: property hooks are a parse
 * error on PHP 8.3, and this file still has to parse there to say why it is
 * skipping.
 */

namespace Rasuvaeff\Understudy\Examples;

use Rasuvaeff\Understudy\Understudy;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_check.php';

if (PHP_VERSION_ID < 80400) {
    echo "skipped: property hooks are PHP 8.4+ syntax, and this is PHP " . PHP_VERSION . "\n";

    exit(0);
}

eval(<<<'PHP'
    namespace Rasuvaeff\Understudy\Examples;

    interface Customer
    {
        public string $name { get; set; }

        public int $visits { get; }
    }
    PHP);

/** @var class-string $contract */
$contract = Customer::class;
$customer = Understudy::for($contract);

// --- Reads answer the mode's type-safe default ------------------------------

check($customer->name === '', 'an unwritten string property reads the loose default');
check($customer->visits === 0, 'an unwritten int property reads its default too');

// --- A { get; set; } property behaves like a plain one ----------------------

$customer->name = 'Ann';

check($customer->name === 'Ann', 'a written value is what later reads answer');

// --- Exactly the declared hooks render --------------------------------------

try {
    $customer->visits = 7;
    check(false, 'a write to a get-only property was expected to be refused');
} catch (\Error) {
    check(true, 'a get-only property refuses a write with PHP\'s own error');
}

// A property read is not a call: nothing lands in the transcript.
check(
    str_contains(Understudy::transcript($customer), 'received no calls'),
    'reading a property records nothing — a read is not a call',
);

Understudy::reset();
