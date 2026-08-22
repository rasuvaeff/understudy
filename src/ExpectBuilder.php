<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy;

/**
 * Configures a call the code under test is expected to make. Everything a
 * stub can say applies here too; the difference is that an expectation is a
 * claim about what happens, checked by `verifyAll()`, and defaults to exactly
 * one call.
 *
 * @template TReturn
 *
 * @extends WhenBuilder<TReturn>
 *
 * @api
 */
final class ExpectBuilder extends WhenBuilder
{
    /**
     * Requires this call to happen in the order it was declared, relative to
     * the other ordered expectations. Other calls may happen in between.
     */
    public function ordered(): static
    {
        $this->expectation->requireOrder();

        return $this;
    }
}
