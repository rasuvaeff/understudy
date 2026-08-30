<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Rasuvaeff\Understudy\Understudy;

use function Rasuvaeff\Understudy\verify;

final class Book
{
    public function __construct(public string $title) {}
}

interface BookRepository
{
    public function save(Book $book): void;
}

$repository = Understudy::for(BookRepository::class);
$original = new Book('Dune');

// The subject saves the book it was handed once, and a rebuilt copy the
// second time. The copy is equal in every field and is not the same object.
$repository->save($original);
$repository->save(new Book('Dune'));

show(static fn() => verify(static fn() => $repository->save($original), times: 2));
