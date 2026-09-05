<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy;

use Rasuvaeff\Understudy\Exception\OutcomeUnavailable;

/**
 * How one call ended: with a value or with a throwable. Kept as its own type
 * because `null` is a perfectly valid return value and cannot double as
 * "nothing was returned".
 *
 * `@internal` since 0.9: dispatch records outcomes through the scalar path
 * (`Invocation::recordReturned()` / `recordThrown()`), so no public path ever
 * builds or receives one — `Invocation::didReturn()`, `returned()`, `didThrow()`
 * and `thrown()` are the public reading of an outcome. It was `@api` by
 * inheritance from the first design, not because anything reached it.
 *
 * @internal
 */
final readonly class Outcome
{
    private function __construct(
        private bool $returned,
        private mixed $value,
        private ?\Throwable $thrown,
    ) {}

    public static function returnedValue(mixed $value): self
    {
        return new self(returned: true, value: $value, thrown: null);
    }

    public static function thrownError(\Throwable $thrown): self
    {
        return new self(returned: false, value: null, thrown: $thrown);
    }

    public function didReturn(): bool
    {
        return $this->returned;
    }

    public function didThrow(): bool
    {
        return !$this->returned;
    }

    /**
     * @param non-empty-string $method used only to render a helpful message
     */
    public function returned(string $method): mixed
    {
        if (!$this->returned) {
            \assert($this->thrown !== null);

            throw OutcomeUnavailable::threwInstead($method, $this->thrown);
        }

        return $this->value;
    }

    public function thrown(): ?\Throwable
    {
        return $this->thrown;
    }
}
