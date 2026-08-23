<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Wire;

class NullableDependency
{
    public function __construct(public readonly ?Repository $repository) {}
}
