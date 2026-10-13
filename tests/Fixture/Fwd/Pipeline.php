<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Fwd;

/**
 * A real class whose bodies forward onto themselves: the template method
 * `run()` calls `apply()` and `normalize()` on `$this`, which is what an
 * inline-forwarding double has to intercept.
 */
class Pipeline extends BasePipeline
{
    /**
     * Declared before every instance property on purpose: a state copy has to
     * skip a static one and carry on, not stop at it.
     */
    public static int $built = 0;

    public ?string $memo = null;

    public function __construct(
        private readonly string $name,
        private array $steps = [],
        string $tag = 'base',
    ) {
        self::$built++;

        parent::__construct($tag);
    }

    /**
     * The template method: everything it does to its input goes through
     * overridable methods of itself.
     */
    public function run(string $input): string
    {
        return $this->normalize($this->apply($input));
    }

    public function apply(string $input): string
    {
        return $input . '+' . implode(',', $this->steps);
    }

    protected function normalize(string $raw): string
    {
        return trim($raw);
    }

    /**
     * Reads the state the constructor set up: promoted readonly, promoted
     * array — exactly what a state copy has to carry onto the double.
     */
    public function describe(): string
    {
        return $this->name . ':' . count($this->steps);
    }

    public function recall(): ?string
    {
        return $this->memo;
    }

    public function addStep(string $step): static
    {
        $this->steps[] = $step;

        return $this;
    }

    public function fill(string &$slot, string $value): void
    {
        $slot = $value . ':' . $this->name;
    }

    public function fail(string $reason): never
    {
        throw new \DomainException($reason);
    }

    /**
     * `: static` resolving to another object — the shape the proxy form
     * refused as a foreign instance, and the inline form has to refuse as a
     * double nobody configured.
     */
    public function duplicate(): static
    {
        return new static($this->name);
    }

    /**
     * Not `: static`, so the override's return type is satisfied by any
     * `Pipeline` and the refusal does not apply.
     */
    public function mirror(): self
    {
        return clone $this;
    }

    /**
     * Computes over an internal copy of itself and never hands it out: the
     * copy has to keep working, or real code breaks where nothing returned a
     * double at all.
     */
    public function preview(string $step): string
    {
        $copy = clone $this;
        $copy->addStep($step);

        return $copy->describe();
    }

    public function copy(): static
    {
        $copy = clone $this;
        $copy->memo = 'copied';

        return $copy;
    }

    /**
     * Clones the instance it was handed rather than itself: the copy belongs
     * to that double, and nothing copied state onto it — `$name` is readonly
     * and uninitialized there, so a copy adopted on this double's behalf
     * raises PHP's own "must not be accessed before initialization".
     */
    public function mergeWith(self $other): string
    {
        $copy = clone $other;

        return $copy->describe();
    }

    /**
     * What `static` resolves to inside a body running inline: the generated
     * class, not this one.
     */
    public function className(): string
    {
        return static::class;
    }
}
