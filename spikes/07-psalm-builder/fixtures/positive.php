<?php

declare(strict_types=1);

namespace UnderstudySpike\Psalm\Fixtures;

use UnderstudySpike\Psalm\Book;
use UnderstudySpike\Psalm\BookRepository;

use function UnderstudySpike\Psalm\when;

function scenario(BookRepository $repo): void
{
    $builder = when(fn () => $repo->find(1));
    /** @psalm-check-type-exact $builder = \UnderstudySpike\Psalm\WhenBuilder<Book|null> */

    when(fn () => $repo->find(2))->returns(new Book());
    when(fn () => $repo->find(3))->returns(null);
    when(static function () use ($repo): ?Book {
        return $repo->find(4);
    })->returns(new Book());
}
