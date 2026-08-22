<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture;

final readonly class Order
{
    public function __construct(
        private int $id = 1,
        private string $status = 'new',
    ) {}

    public function getId(): int
    {
        return $this->id;
    }

    public function getStatus(): string
    {
        return $this->status;
    }
}
