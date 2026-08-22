<?php

declare(strict_types=1);

namespace UnderstudySpike\Psalm;

/**
 * @template T
 */
final class WhenBuilder
{
    /**
     * @param T $value
     */
    public function returns(mixed $value): static
    {
        return $this;
    }
}
