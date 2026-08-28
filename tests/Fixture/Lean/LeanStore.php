<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Lean;

interface LeanStore
{
    public function temporaryUrl(string $path, \DateTimeImmutable $expiresAt, ?Payload $options): ?string;

    public function open(string $path): ?Payload;

    public function note(mixed $payload): void;
}
