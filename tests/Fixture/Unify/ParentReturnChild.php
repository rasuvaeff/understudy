<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

/**
 * `parent` as a return type: Reflection reports the keyword, and the unifier
 * has to resolve it against the declaring class rather than carry it through
 * into a generated class that has a different parent.
 */
class ParentReturnChild extends ParentReturnBase
{
    public function make(): parent
    {
        return new ParentReturnBase();
    }
}
