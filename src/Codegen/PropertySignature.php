<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Codegen;

/**
 * What the dispatcher needs to know about one rendered hooked property,
 * resolved once at generation time — the property analogue of
 * {@see MethodSignature}.
 *
 * Only an *abstract* hook is ever rendered: an interface property, or an
 * `abstract` hooked property on a class. A concrete hook on a class target is
 * inherited and keeps running the target's own code.
 *
 * @internal
 */
final readonly class PropertySignature
{
    /**
     * @param non-empty-string $name
     * @param string           $type rendered property type; empty for an untyped property
     */
    public function __construct(
        public string $name,
        public string $type,
        public bool $hasGet,
        public bool $hasSet,
    ) {}

    public function withHooksOf(self $other): self
    {
        return new self(
            name: $this->name,
            type: $this->type,
            hasGet: $this->hasGet || $other->hasGet,
            hasSet: $this->hasSet || $other->hasSet,
        );
    }
}
