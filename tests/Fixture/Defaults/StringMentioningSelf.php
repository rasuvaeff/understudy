<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Defaults;

/**
 * The words `new` and `self::` appear inside a string literal. A check that
 * looked at the text rather than at the code would read them as an expression
 * and refuse a contract that is perfectly ordinary.
 */
interface StringMentioningSelf
{
    public function describe(string $note = 'see self::PREFIX or new Foo()'): string;
}
