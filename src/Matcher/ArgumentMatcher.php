<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Matcher;

/**
 * Widening every generated parameter with this type is what lets a matcher be
 * passed where the contract declares `int`. It is part of the call protocol,
 * not a value the code under test may ever see: reaching a normal invocation
 * is misuse and fails rather than being treated as an argument.
 *
 * @internal
 */
interface ArgumentMatcher
{
    public function matches(mixed $argument): bool;

    /**
     * Rendered into failure messages in place of the argument, e.g. `any()`.
     *
     * @return non-empty-string
     */
    public function describe(): string;
}
