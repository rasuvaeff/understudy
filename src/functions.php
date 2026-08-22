<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy;

/**
 * Stubs a call: allowed any number of times, including none.
 *
 * ```php
 * when(fn () => $repository->find(123))->returns($book);
 * ```
 *
 * @param callable(): mixed $call
 *
 * @return WhenBuilder<mixed>
 *
 * @api
 */
function when(callable $call): WhenBuilder
{
    return Understudy::when($call);
}

/**
 * Asserts, after the fact, how many times a call was made.
 *
 * ```php
 * verify(fn () => $repository->recordView($book), times: 2);
 * ```
 *
 * @param callable(): mixed $call
 * @param int<0, max>|null  $times
 * @param int<0, max>|null  $minimum
 * @param int<0, max>|null  $maximum
 *
 * @api
 */
function verify(
    callable $call,
    ?int $times = null,
    ?int $minimum = null,
    ?int $maximum = null,
    bool $never = false,
): void {
    Understudy::verify($call, $times, $minimum, $maximum, $never);
}
