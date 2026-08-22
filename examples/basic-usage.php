<?php

declare(strict_types=1);

/**
 * Stubbing, verifying and reading the call log — the whole loop, without a
 * test runner. Run it with:
 *
 *   docker run --rm -v "$PWD":/app -w /app composer:2 php examples/basic-usage.php
 */

namespace Rasuvaeff\Understudy\Examples;

use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Exception\StrictModeViolation;
use Rasuvaeff\Understudy\Exception\VerificationFailed;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Understudy;

use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

require __DIR__ . '/../vendor/autoload.php';

final readonly class Book
{
    public function __construct(public string $title) {}
}

interface BookRepository
{
    public function find(int $id): ?Book;

    public function save(Book $book): void;

    public function count(): int;
}

$repository = Understudy::for(BookRepository::class);

// --- Stubbing ---------------------------------------------------------------

// Order matters: the most recently registered stub is tried first, so the
// broad one goes down first and the specific ones layer on top of it.
when(fn() => $repository->find(Arg::any()))
    ->answers(static fn(Invocation $call): Book => new Book('book #' . $call->args[0]));
when(fn() => $repository->find(1))->returns(new Book('Dune'));
when(fn() => $repository->find(404))->throws(new \RuntimeException('not found'));

echo "find(1)     -> ", $repository->find(1)?->title, "\n";
echo "find(7)     -> ", $repository->find(7)?->title, "  (matched by Arg::any())\n";

try {
    $repository->find(404);
} catch (\RuntimeException $e) {
    echo "find(404)   -> threw ", $e->getMessage(), "\n";
}

// A loose double answers anything else with a type-safe default.
echo "count()     -> ", var_export($repository->count(), true), "  (loose default)\n";

// --- Verifying --------------------------------------------------------------

$book = new Book('Dune');
$repository->save($book);
$repository->save($book);

verify(fn() => $repository->save($book), times: 2);
echo "\nverify(save, times: 2) passed\n";

try {
    Understudy::verify(fn() => $repository->save($book), times: 5);
} catch (VerificationFailed $e) {
    echo "\n", $e->getMessage(), "\n";
}

// --- Reading the call log ---------------------------------------------------

$calls = Understudy::calls(fn() => $repository->find(Arg::any()));

echo "\nfind() was called ", count($calls), " times:\n";

foreach ($calls as $call) {
    $outcome = $call->didThrow()
        ? 'threw ' . $call->thrown()?->getMessage()
        : 'returned ' . ($call->returned()?->title ?? 'null');

    echo '  find(', $call->args[0], ') ', $outcome, "\n";
}

// --- Strict mode ------------------------------------------------------------

$strict = Understudy::for(BookRepository::class);
Understudy::label($strict, 'strict catalogue');
Understudy::strict($strict);

try {
    $strict->count();
} catch (StrictModeViolation $e) {
    echo "\n", $e->getMessage(), "\n";
}

Understudy::reset();
