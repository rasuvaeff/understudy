<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Perf\Support;

/**
 * Doubled once before anything is measured, so that a library's own class
 * loading is not billed to the first contract that happens to be measured.
 */
interface Warmup
{
    public function ping(): bool;
}
