<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Defaults;

use Rasuvaeff\Understudy\Tests\Fixture\Book;

/**
 * Every shape in which a declared type admits both `null` and something a
 * test might have registered.
 *
 * @internal
 */
interface NullableShapes
{
    public function shorthand(): ?Book;

    public function union(): ?Book;

    public function unionWithScalar(): Book|string|null;

    public function unregistered(): ?\stdClass;
}
