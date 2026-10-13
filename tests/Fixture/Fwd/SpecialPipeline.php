<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Fwd;

/**
 * A donor that is a *subclass* of the doubled class. `parent::` on a double of
 * `Pipeline` reaches `Pipeline`'s bodies, not this one's override — so a
 * double standing in for `Pipeline` and handed one of these keeps the proxy
 * form, where the override answers.
 */
class SpecialPipeline extends Pipeline
{
    public function apply(string $input): string
    {
        return strtoupper($input) . '!';
    }
}
