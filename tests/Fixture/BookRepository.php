<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture;

interface BookRepository
{
    public function find(int $id): ?Book;

    public function save(Book $book): void;

    public function titles(): array;

    public function count(): int;

    public function abort(string $reason): never;

    public function stream(): \Generator;

    public function describe(): string|int;

    public function tag(string $name, int $weight = 1): string;
}
