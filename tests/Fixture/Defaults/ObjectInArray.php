<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Defaults;

/**
 * An object reachable only as a value — the default is a constant holding one,
 * so there is no `new` in the source to render.
 */
interface ObjectInArray
{
    public function hold(array $box = ObjectBox::BOXED): string;
}
