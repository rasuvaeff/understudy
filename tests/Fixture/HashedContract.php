<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture;

/**
 * Doubled by exactly one test, so that test really runs the codegen path
 * rather than hitting the blueprint cache another test already filled.
 */
interface HashedContract
{
    public function ping(): string;
}
