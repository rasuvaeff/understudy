<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Matcher;

use Rasuvaeff\Understudy\Captor;

/**
 * The matcher a {@see Captor} places into a specification.
 *
 * Matching and recording are deliberately separate: `matches()` is asked
 * during dispatch about calls the rest of the specification may yet reject,
 * and again while a failure message is rendered — so it only answers, and the
 * dispatcher records the value through the captor once the whole
 * specification matched.
 *
 * @internal
 */
final readonly class Capturing implements ArgumentMatcher
{
    /**
     * @param class-string|null $class
     */
    public function __construct(
        public Captor $captor,
        private ?string $class = null,
    ) {}

    #[\Override]
    public function matches(mixed $argument): bool
    {
        return $this->class === null || $argument instanceof $this->class;
    }

    #[\Override]
    public function describe(): string
    {
        return $this->class === null ? 'capture()' : 'capture(' . $this->class . ')';
    }
}
