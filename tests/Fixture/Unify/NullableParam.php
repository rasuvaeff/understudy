<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

use Rasuvaeff\Understudy\Tests\Fixture\Book;

interface NullableParam
{
    public function store(?Book $book): string;

    public function tail(int $first, string ...$rest): string;
}
