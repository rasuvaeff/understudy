<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture;

/**
 * A typed public property that was never initialized, next to a `__get()` that
 * refuses: reading either is running user code while a message is rendered.
 */
final class Draft
{
    public string $title;

    public function __get(string $property): never
    {
        throw new \LogicException('Rendering a message must not read ' . $property);
    }
}
