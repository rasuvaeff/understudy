<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Matcher;

/**
 * Matches an argument at least one operand accepts. With literals it reads as
 * a set — `anyOf('draft', 'review')` — and with matchers as a disjunction.
 *
 * @internal
 */
final readonly class AnyOf implements ArgumentMatcher
{
    /**
     * @param non-empty-list<mixed> $operands
     */
    public function __construct(private array $operands) {}

    #[\Override]
    public function matches(mixed $argument): bool
    {
        /** @var mixed $operand */
        foreach ($this->operands as $operand) {
            if (Operand::matches($operand, $argument)) {
                return true;
            }
        }

        return false;
    }

    #[\Override]
    public function describe(): string
    {
        return 'anyOf(' . Operand::describeAll($this->operands) . ')';
    }
}
