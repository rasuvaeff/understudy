<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Rasuvaeff\Understudy\Understudy;

use function Rasuvaeff\Understudy\expect;

final class Book
{
    public function __construct(public string $title) {}
}

interface BookRepository
{
    public function save(Book $book): void;
}

$repository = Understudy::for(BookRepository::class);
$expected = new Book('Dune');

expect(static fn() => $repository->save($expected));

// The subject also saves something the test never asked about.
$repository->save($expected);
$repository->save(new Book('Neuromancer'));

// The expectation alone is satisfied: it counted its own call and says
// nothing about the rest.
Understudy::verifyAll();
echo "verifyAll() alone: passed\n\n";

show(static fn() => Understudy::nothingElse($repository));
