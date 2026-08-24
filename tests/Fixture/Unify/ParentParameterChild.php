<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

/**
 * `parent` as a PARAMETER type, which is the dangerous half. A return type
 * carried through literally is merely narrower than promised; a parameter is
 * illegally narrow, and PHP refuses the generated class outright.
 */
class ParentParameterChild extends ParentReturnBase
{
    public function accept(parent $value): bool
    {
        return $value instanceof ParentReturnBase;
    }
}
