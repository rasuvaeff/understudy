<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture;

/**
 * A contract that marks one of its parameters sensitive, the way PHP asks
 * code to say "do not print this".
 */
interface Credentials
{
    public function login(string $user, #[\SensitiveParameter] string $password): bool;
}
