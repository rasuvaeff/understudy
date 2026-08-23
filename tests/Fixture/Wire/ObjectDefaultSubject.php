<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Wire;

class ObjectDefaultSubject
{
    public function __construct(
        public readonly Repository $repository,
        public readonly CountingDefault $stamp = new CountingDefault(),
    ) {}
}
