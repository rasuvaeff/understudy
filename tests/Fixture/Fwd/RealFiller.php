<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Fwd;

class RealFiller implements Filler
{
    #[\Override]
    public function fill(string &$slot, string $value): void
    {
        $slot = $value;
    }

    #[\Override]
    public function collapse(): never
    {
        throw new \DomainException('the real implementation refused');
    }
}
