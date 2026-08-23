<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Wire;

final class VariadicObjectDefaultSubject
{
    /** @var list<string> */
    public readonly array $tags;

    public function __construct(
        public readonly Repository $repository,
        public readonly CountingDefault $stamp = new CountingDefault(),
        string ...$tags,
    ) {
        $this->tags = array_values($tags);
    }
}
