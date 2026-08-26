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
 * Declares a call the code under test is expected to make: exactly once unless
 * `times()` says otherwise, checked by `Understudy::verifyAll()`.
 *
 * ```php
 * expect(fn () => $repository->save($book));
 * ```
 *
 * Pest declares a global `expect()` of its own. In a Pest suite either import
 * this one under another name — `use function Rasuvaeff\Understudy\expect as
 * expectCall;` — or call `Understudy::expect()`, which cannot collide.
 *
 * @param callable(): mixed $call
 *
 * @return ExpectBuilder<mixed>
 *
 * @api
 */
function expect(callable $call): ExpectBuilder
{
    return Understudy::expect($call);
}

/**
 * Arms a protocol before the code under test runs: a call that breaks the order
 * fails at that call, not in teardown.
 *
 * ```php
 * expectSequence(
 *     fn () => $repo->begin(),
 *     fn () => $repo->save($book),
 *     fn () => $repo->commit(),
 * );
 * ```
 *
 * @param callable(): mixed ...$calls
 *
 * @api
 */
function expectSequence(callable ...$calls): void
{
    Understudy::expectSequence(...$calls);
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
