<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Wire;

class NeedsSealedCollaborator
{
    public function __construct(public readonly SealedCollaborator $sealed) {}
}
