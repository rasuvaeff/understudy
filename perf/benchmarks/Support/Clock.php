<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Perf\Support;

/**
 * The narrowest contract there is: one method, one scalar return. Everything a
 * library does per double that is not proportional to the interface shows up
 * here undiluted.
 */
interface Clock
{
    public function now(): int;
}
