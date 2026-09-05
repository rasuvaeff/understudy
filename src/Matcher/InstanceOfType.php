<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Matcher;

use Rasuvaeff\Understudy\Exception\InvalidSpecificationArgument;

/**
 * @internal
 */
final readonly class InstanceOfType implements ArgumentMatcher
{
    /**
     * @param class-string $type
     */
    public function __construct(private string $type)
    {
        // A type nothing can be an instance of matches nothing, forever, and
        // says so nowhere: the reader sees "expected … but it was never
        // called" and looks for the cause in the subject under test. The
        // `class-string` annotation catches a literal under an analyser, not
        // a name assembled at runtime and not a project without one — and
        // `Understudy::for()` refuses the same input in the same breath.
        if (!class_exists($type) && !interface_exists($type)) {
            throw InvalidSpecificationArgument::unknownType($type);
        }
    }

    #[\Override]
    public function matches(mixed $argument): bool
    {
        return $argument instanceof $this->type;
    }

    #[\Override]
    public function describe(): string
    {
        return 'instanceOf(' . $this->type . ')';
    }
}
