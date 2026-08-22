<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Expectation;

use Rasuvaeff\Understudy\Invocation;

/**
 * Returns each value in turn, then repeats the last one — the sequence
 * semantics Mockery and NSubstitute users already expect.
 *
 * @internal
 */
final class ReturnValues implements Action
{
    /** @var int<0, max> */
    private int $position = 0;

    /**
     * @param non-empty-list<mixed> $values
     */
    public function __construct(private readonly array $values) {}

    #[\Override]
    public function perform(Invocation $invocation): mixed
    {
        // Indexed rather than end(), which moves the array pointer and so
        // cannot be applied to a readonly property.
        /** @var mixed $value */
        $value = $this->values[$this->position] ?? $this->values[count($this->values) - 1];

        if ($this->position < count($this->values) - 1) {
            $this->position++;
        }

        return $value;
    }
}
