<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Matcher;

/**
 * Matches an argument every operand accepts. An operand is a matcher or a
 * literal, the same pair `not()` takes, so `allOf(instanceOf(Book::class),
 * which('getTitle', 'Dune'))` composes without a hand-written predicate.
 *
 * @internal
 */
final readonly class AllOf implements ArgumentMatcher
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
            if (!Operand::matches($operand, $argument)) {
                return false;
            }
        }

        return true;
    }

    #[\Override]
    public function describe(): string
    {
        return 'allOf(' . Operand::describeAll($this->operands) . ')';
    }
}
