<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Wire;

class ReferenceConstructor
{
    /** @param array<mixed> $sink */
    public function __construct(array &$sink) {}
}
