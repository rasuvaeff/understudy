<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Defaults;

/** Reflection reports this default as `parent::INHERITED`. */
abstract class ParentConstant extends ParentHolder
{
    abstract public function greet(string $prefix = parent::INHERITED): string;
}
