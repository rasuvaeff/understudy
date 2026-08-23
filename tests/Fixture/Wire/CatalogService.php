<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Wire;

class CatalogService
{
    public function __construct(
        private readonly Repository $repository,
        private readonly Reporter $reporter,
        private readonly int $limit = 10,
    ) {}

    public function lookup(int $id): string
    {
        $found = $this->repository->find($id);
        $this->reporter->report($found);

        return $found . '/' . $this->limit;
    }
}
