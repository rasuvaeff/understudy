<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Wire;

class UntypedSubject
{
    public function __construct(public readonly mixed $anything = null, public $untyped = null) {}
}
