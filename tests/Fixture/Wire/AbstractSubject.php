<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Wire;

abstract class AbstractSubject
{
    public function __construct(public readonly Repository $repository) {}
}
