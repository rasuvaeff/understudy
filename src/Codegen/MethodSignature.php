<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Codegen;

/**
 * What the dispatcher needs to know about one doubled method, resolved once at
 * generation time so no Reflection happens on the hot path.
 *
 * @internal
 */
final readonly class MethodSignature
{
    /**
     * @param non-empty-string $name
     * @param string           $parameters rendered parameter list; empty for a method without parameters
     * @param non-empty-string $arguments  expression collecting every parameter, defaults included
     * @param non-empty-string $returnType rendered return type of the override
     * @param bool $hasReferenceParameters gates the argument snapshots the call log
     *                                      takes, so the common path pays nothing
     * @param 'public'|'protected' $visibility a protected method is overridden and dispatched like any
     *                                         other, but native visibility keeps it out of setup closures
     * @param list<int> $sensitiveParameters positions the contract marked `#[\SensitiveParameter]`.
     *                                       Resolved here, with the rest of the reflection, because a
     *                                       failure message is rendered on the hot path of a failing
     *                                       test and PHP redacts these in its own traces
     * @param array<int, non-empty-string> $parameterNames the contract's own name for each fixed
     *                                       parameter, so a call can be read by name
     * @param array<int, \ReflectionParameter|null> $optionalParameters the positions the contract
     *                                       lets a caller omit, each with the parameter that declared
     *                                       the default — null where the position is optional only
     *                                       because another target does not declare it at all. A
     *                                       position missing from this map is required
     */
    public function __construct(
        public string $name,
        public string $parameters,
        public string $arguments,
        public string $returnType,
        public bool $returnsNever,
        public bool $returnsVoid,
        public bool $returnsReference,
        public bool $hasReferenceParameters = false,
        public bool $static = false,
        public string $visibility = 'public',
        public array $sensitiveParameters = [],
        public array $parameterNames = [],
        public array $optionalParameters = [],
    ) {}

    /**
     * The value the contract gives a parameter the caller omitted.
     *
     * Evaluated per call, which is what PHP does for a default that builds an
     * object. `null` is also the answer for a position that is optional only
     * because a second target does not declare it — there is no contract
     * default to reproduce there, and null is what the parameter used to
     * carry when the double rendered defaults itself.
     */
    public function defaultAt(int $position): mixed
    {
        $parameter = $this->optionalParameters[$position] ?? null;

        return $parameter?->getDefaultValue();
    }

    public function isOptional(int $position): bool
    {
        return \array_key_exists($position, $this->optionalParameters);
    }
}
