<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Def;

final class NullLogger implements Logger
{
    /** @var list<string> */
    public array $written = [];

    public function log(string $message): void
    {
        $this->written[] = $message;
    }
}
