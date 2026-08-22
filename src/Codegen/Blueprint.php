<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Codegen;

/**
 * The compiled description of one understudy class: which contracts it stands
 * in for, and the signature of every method it overrides.
 *
 * @internal
 */
final readonly class Blueprint
{
    /**
     * @param class-string                          $generatedClass
     * @param non-empty-list<class-string>          $contracts
     * @param array<non-empty-string, MethodSignature> $methods
     */
    public function __construct(
        public string $generatedClass,
        public array $contracts,
        public array $methods,
    ) {}

    /**
     * @param non-empty-string $name
     */
    public function method(string $name): ?MethodSignature
    {
        return $this->methods[$name] ?? null;
    }

    /**
     * The name failure messages use when no explicit label was set: the short
     * name of the primary contract, which is what the reader recognises.
     *
     * @return non-empty-string
     */
    public function displayName(): string
    {
        $primary = $this->contracts[0];
        $position = strrpos($primary, '\\');
        $short = $position === false ? $primary : substr($primary, $position + 1);

        \assert($short !== '');

        return $short;
    }
}
