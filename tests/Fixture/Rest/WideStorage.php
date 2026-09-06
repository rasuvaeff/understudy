<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Rest;

/**
 * A contract with a wide required signature — the shape `Arg::rest()` exists
 * for — plus an optional parameter and a variadic to pin the boundaries.
 */
interface WideStorage
{
    public function recordOutcome(
        string $key,
        int $outcome,
        array $config,
        \DateTimeImmutable $now,
        bool $admission,
        ?string $admittedAt,
        string $attemptId,
    ): ?string;

    public function tag(string $name, int $weight = 1): void;

    /** The default differs from the position it sits at, so one cannot stand in for the other. */
    public function note(string $text, int $level = 7): void;

    public function emit(string $channel, string ...$payloads): int;
}
