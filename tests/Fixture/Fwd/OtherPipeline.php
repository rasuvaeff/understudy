<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Fwd;

/**
 * A second class declaring a method of the same name as `Pipeline`: two
 * inline doubles must each run their own class's body, which is what keeps
 * the dispatcher's per-class reflection cache keyed by both class and method.
 */
class OtherPipeline
{
    public function apply(string $input): string
    {
        return 'other(' . $input . ')';
    }
}
