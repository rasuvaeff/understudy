<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

/**
 * The same method name with a return no `parent` can satisfy — so unifying it
 * with {@see ParentReturnChild} produces the conflict message, which is where
 * the keyword used to survive unresolved.
 */
interface ParentReturnConflict
{
    public function make(): string;
}
