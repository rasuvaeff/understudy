<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Ret;

use Rasuvaeff\Understudy\Tests\Fixture\Book;
use Rasuvaeff\Understudy\Tests\Fixture\BookRepository;

/**
 * A real implementation of the fixture contract, for `instanceof` checks.
 */
class PlainRepository implements BookRepository
{
    #[\Override]
    public function find(int $id): ?Book
    {
        return null;
    }

    #[\Override]
    public function save(Book $book): void {}

    #[\Override]
    public function titles(): array
    {
        return [];
    }

    #[\Override]
    public function count(): int
    {
        return 0;
    }

    #[\Override]
    public function abort(string $reason): never
    {
        throw new \RuntimeException($reason);
    }

    #[\Override]
    public function stream(): \Generator
    {
        yield from [];
    }

    #[\Override]
    public function describe(): string|int
    {
        return '';
    }

    #[\Override]
    public function tag(string $name, int $weight = 1): string
    {
        return $name;
    }
}
