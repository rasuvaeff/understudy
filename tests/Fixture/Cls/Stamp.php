<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Cls;

/**
 * Its own file because PSR-4 says so: `ObjectDefaultContract` names it in a
 * parameter default, and an autoloader asked for `Stamp` looks for
 * `tests/Fixture/Cls/Stamp.php`. Declaring it beside another class worked only
 * for as long as something else had already loaded that file.
 */
final class Stamp
{
    public function __construct(public int $at = 0, public string $tag = '') {}
}
