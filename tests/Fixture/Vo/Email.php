<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Vo;

final readonly class Email
{
    public function __construct(
        public string $to,
        public string $subject = '',
        private int $priority = 0,
    ) {}

    public function priority(): int
    {
        return $this->priority;
    }
}
