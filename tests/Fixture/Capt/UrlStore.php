<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Capt;

interface UrlStore
{
    public function temporaryUrl(string $path, \DateTimeImmutable $expiresAt, ?DeliveryOptions $options): ?string;

    public function note(mixed $payload): void;
}
