<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

/**
 * No return type at all — the widest declaration there is, and the one the
 * unifier has to read as `mixed` rather than as "nothing".
 */
interface UntypedReturn
{
    public function value();
}
