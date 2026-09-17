<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Ret;

use Rasuvaeff\Understudy\Tests\Fixture\Book;
use Rasuvaeff\Understudy\Tests\Fixture\Librarian;

final class RepositoryAndLibrarian extends PlainRepository implements Librarian
{
    #[\Override]
    public function pick(): Book
    {
        return new Book('x');
    }
}
