<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Defaults;

/** A protected constant of a parent: named, but not reachable from a subclass. */
abstract class HiddenConstant extends ParentHolder
{
    abstract public function step(int $n = parent::HIDDEN_STEP): int;
}
