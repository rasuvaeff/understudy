<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Wire;

class NullableScalar
{
    public function __construct(public readonly ?string $name, public readonly ?Repository $repository) {}
}
