<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Vo;

interface Mailer
{
    public function send(object $message): bool;
}
