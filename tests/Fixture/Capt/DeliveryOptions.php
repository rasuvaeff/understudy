<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Capt;

final readonly class DeliveryOptions
{
    public function __construct(
        public string $downloadName,
    ) {}
}
