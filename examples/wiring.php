<?php

declare(strict_types=1);

/**
 * `wire()` — build the real subject with a double for every collaborator its
 * constructor asks for.
 *
 *   docker run --rm -v "$PWD":/app -w /app composer:2 php examples/wiring.php
 */

namespace Rasuvaeff\Understudy\Examples;

use Rasuvaeff\Understudy\Exception\CannotWire;
use Rasuvaeff\Understudy\Understudy;

use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_check.php';

interface Mailer
{
    public function send(string $to, string $body): bool;
}

interface Ledger
{
    public function record(string $entry): void;
}

final class Checkout
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly Ledger $ledger,
        private readonly string $from = 'shop@example.test',
    ) {}

    public function buy(string $customer): bool
    {
        $this->ledger->record($customer);

        return $this->mailer->send($customer, 'from ' . $this->from);
    }
}

// --- Every dependency, by parameter name ---------------------------------------

echo "wire()\n";

['sut' => $checkout, 'doubles' => $doubles] = Understudy::wire(Checkout::class);

check($checkout instanceof Checkout, 'the subject is real — only its collaborators are doubles');
check(array_keys($doubles) === ['mailer', 'ledger'], 'doubles are keyed by parameter name: ' . implode(', ', array_keys($doubles)));

// `$from` has a default, so `wire()` leaves it to PHP. Reading a default would
// mean evaluating it, and `= new Foo()` is a default too.
check(!isset($doubles['from']), 'a parameter with a default is left alone, never doubled');

$mailer = $doubles['mailer'];
when(fn() => $mailer->send('ada@example.test', 'from shop@example.test'))->returns(true);

check($checkout->buy('ada@example.test'), 'the wired doubles are the ones the subject actually calls');

$ledger = $doubles['ledger'];
verify(fn() => $ledger->record('ada@example.test'));
check(true, 'and every one of them is a spy');

Understudy::reset();

// --- Overriding one dependency --------------------------------------------------

echo "\nwire() with an override\n";

$recorded = [];
$realLedger = new class ($recorded) implements Ledger {
    /**
     * @param list<string> $entries
     */
    public function __construct(private array &$entries) {}

    public function record(string $entry): void
    {
        $this->entries[] = $entry;
    }
};

['sut' => $checkout, 'doubles' => $doubles] = Understudy::wire(Checkout::class, ['ledger' => $realLedger]);

check(array_keys($doubles) === ['mailer'], 'an override is yours already, so it does not come back in doubles');

$checkout->buy('grace@example.test');

check($recorded === ['grace@example.test'], 'the real collaborator was used, not a double');

Understudy::reset();

// --- What it refuses, and when ----------------------------------------------------

echo "\nwire() refusals\n";

final class NeedsAnInt
{
    public function __construct(public readonly int $retries) {}
}

try {
    Understudy::wire(NeedsAnInt::class);
    check(false, 'a dependency that cannot be doubled was expected to be refused');
} catch (CannotWire $refusal) {
    // Before the constructor runs: a half-built subject would report a TypeError
    // from inside code the test never wrote.
    check(
        str_contains($refusal->getMessage(), 'retries'),
        'a parameter with no default and no doublable type is refused, and named: '
            . explode("\n", $refusal->getMessage())[0],
    );
}

Understudy::reset();
