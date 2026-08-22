<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy;

use Rasuvaeff\Understudy\Matcher\AnyArgument;
use Rasuvaeff\Understudy\Matcher\ArgumentMatcher;

/**
 * Argument matchers, usable only inside a specification closure:
 *
 * ```php
 * when(fn () => $repository->find(Arg::any()))->returns($book);
 * ```
 *
 * @api
 */
final class Arg
{
    private function __construct() {}

    public static function any(): ArgumentMatcher
    {
        return new AnyArgument();
    }
}
