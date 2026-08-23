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
     * @param 'public'|'protected' $visibility a protected method is overridden and dispatched like any
     *                                         other, but native visibility keeps it out of setup closures
     */
    public function __construct(
        public string $name,
        public string $parameters,
        public string $arguments,
        public string $returnType,
        public bool $returnsNever,
        public bool $returnsVoid,
        public bool $returnsReference,
        public bool $static = false,
        public string $visibility = 'public',
    ) {}
}
