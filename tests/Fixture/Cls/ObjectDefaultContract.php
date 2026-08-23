<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Cls;

/**
 * A `new` default, kept apart from {@see DefaultsContract} so that the codegen
 * lines it exercises are attributed to the test that covers it — Infection
 * picks tests by the lines they actually ran, and a shared fixture would have
 * been compiled by whichever test came first.
 */
interface ObjectDefaultContract
{
    public function stamped(Stamp $stamp = new Stamp(7, 'x')): string;
}
