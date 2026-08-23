<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Def;

/**
 * Two unrelated interfaces at the same distance, which is what a tie looks
 * like, plus `Logger` one step further through `Audited`.
 */
class AuditedLogger implements Audited, Timestamped
{
    public function log(string $message): void {}

    public function trail(): array
    {
        return [];
    }

    public function at(): int
    {
        return 0;
    }
}
