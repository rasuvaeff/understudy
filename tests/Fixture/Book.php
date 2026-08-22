<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture;

final readonly class Book
{
    public function __construct(public string $title = 'untitled') {}
}
