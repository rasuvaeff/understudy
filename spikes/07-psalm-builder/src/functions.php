<?php

declare(strict_types=1);

namespace UnderstudySpike\Psalm;

/**
 * Runtime fallback signature; the plugin narrows the return type to
 * WhenBuilder<TReturn> of the single method call inside the closure.
 *
 * @return WhenBuilder<mixed>
 */
function when(callable $call): WhenBuilder
{
    return new WhenBuilder();
}
