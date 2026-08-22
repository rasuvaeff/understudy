<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy;

use Rasuvaeff\Understudy\Matcher\AnyArgument;

/**
 * Argument matchers, usable only inside a specification closure:
 *
 * ```php
 * when(fn () => $repository->find(Arg::any()))->returns($book);
 * ```
 *
 * Every matcher is declared to return `mixed` rather than the internal
 * `ArgumentMatcher` type. That is not vagueness — it is what the matcher
 * means. A matcher stands in for a value of whatever type the parameter
 * declares, and it is never consumed as a value: the runtime intercepts it
 * while recording the call specification. Declaring the concrete class instead
 * would make `find(Arg::any())` a type error in every IDE and analyser for a
 * contract that says `find(int $id)`, on the first line of the first example
 * anyone copies — while `mixed` is accepted everywhere and stays honest.
 *
 * `understudy-psalm` narrows this to the parameter's declared type, so users
 * of the plugin get a real check rather than `mixed`.
 *
 * @api
 */
final class Arg
{
    private function __construct() {}

    /**
     * Matches any argument, including `null`.
     */
    public static function any(): mixed
    {
        return new AnyArgument();
    }
}
