<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture;

/**
 * A non-nullable object return, so the loose-default registry is actually
 * asked: a nullable one answers `null` before the registry is consulted.
 *
 * @internal
 */
interface Librarian
{
    public function pick(): Book;
}
