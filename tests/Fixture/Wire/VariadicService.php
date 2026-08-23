<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Wire;

class VariadicService
{
    /** @var list<string> */
    public readonly array $tags;

    public function __construct(
        public readonly Repository $repository,
        public readonly int $priority = 10,
        string ...$tags,
    ) {
        $this->tags = array_values($tags);
    }
}
