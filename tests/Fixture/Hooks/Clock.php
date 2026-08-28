<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Hooks;

/**
 * A doublable collaborator for object-typed hooked properties. Deliberately
 * free of 8.4 syntax: the hooked contracts referencing it are built by eval,
 * this file must parse on 8.3.
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
