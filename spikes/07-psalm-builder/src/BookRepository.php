<?php

declare(strict_types=1);

namespace UnderstudySpike\Psalm;

interface BookRepository
{
    public function find(int $id): ?Book;
}
