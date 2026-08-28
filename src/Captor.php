<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy;

use Rasuvaeff\Understudy\Exception\NothingCaptured;
use Rasuvaeff\Understudy\Matcher\Capturing;

/**
 * A typed argument captor, built by {@see Arg::captor()}.
 *
 * ```php
 * $options = Arg::captor(DeliveryOptions::class);
 * when(fn () => $store->temporaryUrl(Arg::any(), Arg::any(), $options->capture()))
 *     ->returns('https://…');
 *
 * $subject->run();
 *
 * Assert::same($options->last()->downloadName, 'my report.pdf');
 * ```
 *
 * `capture()` matches like `Arg::instanceOf()` for the typed form and like
 * `Arg::any()` for the untyped one, and the argument is recorded only when the
 * whole specification matched the call — a matcher can be asked about a call
 * whose other arguments then reject it, and about no call at all while a
 * failure message is rendered, and neither of those is a capture.
 *
 * Values live until the context that captured them ends: `reset()` — the
 * adapters call it after each test — and a closing `Understudy::scope()` drop
 * them, the same way they drop the call log. The captor object itself is
 * reusable after that; it is simply empty again.
 *
 * @template T
 *
 * @api
 */
final class Captor
{
    /** @var list<T> */
    private array $values = [];

    /**
     * @param class-string|null $class what {@see capture()} requires of the
     *                                 argument; null accepts anything
     */
    public function __construct(
        private readonly ?string $class = null,
    ) {}

    /**
     * The matcher to place where the argument to capture goes. Declared
     * `mixed` for the same reason every `Arg::*` factory is: it stands in for
     * a value of whatever type the parameter declares.
     */
    public function capture(): mixed
    {
        return new Capturing($this, $this->class);
    }

    /**
     * The most recently captured value.
     *
     * @return T
     *
     * @throws NothingCaptured when no matched call carried one
     */
    public function last(): mixed
    {
        $values = $this->values;

        if ($values === []) {
            throw NothingCaptured::forCaptor($this->class);
        }

        return $values[array_key_last($values)];
    }

    /**
     * Every captured value, in call order.
     *
     * @return list<T>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * @internal called by the dispatcher once the whole specification matched
     */
    public function record(mixed $value): void
    {
        /** @var T $value */
        $this->values[] = $value;
    }

    /**
     * @internal called when the context the values were captured in ends
     */
    public function discard(): void
    {
        $this->values = [];
    }
}
