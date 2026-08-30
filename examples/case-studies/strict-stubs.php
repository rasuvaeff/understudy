<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Rasuvaeff\Understudy\Understudy;

use function Rasuvaeff\Understudy\when;

final class Book
{
    public function __construct(public string $title) {}
}

interface BookRepository
{
    public function find(int $id): ?Book;

    public function findBySlug(string $slug): ?Book;
}

$repository = Understudy::for(BookRepository::class);

when(static fn () => $repository->find(7))->returns(new Book('Dune'));

// This one described a call the subject stopped making when the lookup moved
// to find(). Nothing fails by default: a stub is permission.
when(static fn () => $repository->findBySlug('dune'))->returns(new Book('Dune'));

$repository->find(7);

Understudy::verifyAll();
echo "verifyAll() alone: passed\n\n";

show(static fn () => Understudy::verifyAll(strictStubs: true));
