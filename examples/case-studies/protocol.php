<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Rasuvaeff\Understudy\Understudy;

final class Book
{
    public function __construct(public string $title) {}
}

interface BookRepository
{
    public function begin(): void;

    public function find(int $id): ?Book;

    public function save(Book $book): void;

    public function commit(): void;
}

$repository = Understudy::for(BookRepository::class);
$book = new Book('Dune');

Understudy::expectSequence(
    static fn () => $repository->begin(),
    static fn () => $repository->save($book),
    static fn () => $repository->commit(),
);

// The subject reads between two steps of the protocol. Nothing configured
// that read, so the protocol cannot tell "not part of this" from "out of turn".
show(static function () use ($repository): void {
    $repository->begin();
    $repository->find(7);
});
