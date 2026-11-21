<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Vo;

/**
 * A mutable node, so a test can close a cycle.
 */
final class Node
{
    public ?Node $next = null;

    public static ?int $count = null;

    public int $unset;

    public function __construct(public string $name) {}
}
