<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Defaults;

use Rasuvaeff\Understudy\Exception\AmbiguousDefaultFactory;
use Rasuvaeff\Understudy\Exception\InvalidDefaultValue;

/**
 * Per-context registry of loose-default factories.
 *
 * A nested double of `LoggerInterface` answers every call with a default and
 * tells you nothing; a `NullLogger` is what the test actually wanted. This is
 * where a test says so.
 *
 * Lookup is by distance in the type graph, not by registration order: an exact
 * match wins, then the nearest registered ancestor. Two ancestors at the same
 * distance raise rather than let whichever was registered first decide — a
 * tie has no order to fall back on that a reader could predict.
 *
 * @internal
 */
final class DefaultFactories
{
    /** @var array<class-string, \Closure(): mixed> */
    private array $factories = [];

    /**
     * True when nothing has been registered, which is the common case: the
     * resolver asks before doing anything that costs, because `class_exists()`
     * on a builtin type name is an autoloader round trip on every unmatched
     * call.
     */
    public function isEmpty(): bool
    {
        return $this->factories === [];
    }

    /**
     * @param class-string      $contract
     * @param \Closure(): mixed $factory
     */
    public function register(string $contract, \Closure $factory): void
    {
        $this->factories[ltrim($contract, '\\')] = $factory;
    }

    /**
     * The value a registered factory produces for this type, or null when none
     * applies. The result is checked against the requested type: a factory that
     * answers with the wrong thing is a mistake in the test, not a value to
     * pass on.
     *
     * @param class-string $requested
     *
     * @return array{mixed}|null a one-element list, so that a factory returning
     *                           null is not mistaken for "no factory"
     */
    public function valueFor(string $requested): ?array
    {
        $factory = $this->factoryFor($requested);

        if ($factory === null) {
            return null;
        }

        /** @var mixed $value */
        $value = $factory();

        if (!$value instanceof $requested) {
            throw InvalidDefaultValue::ofWrongType($requested, get_debug_type($value));
        }

        return [$value];
    }

    /**
     * @param class-string $requested
     *
     * @return (\Closure(): mixed)|null
     */
    private function factoryFor(string $requested): ?\Closure
    {
        $requested = ltrim($requested, '\\');

        if (isset($this->factories[$requested])) {
            return $this->factories[$requested];
        }

        // Reflection needs a real type, and autoloading here would be an
        // autoloader round trip for every name the builtin table could have
        // answered on its own.
        if (!class_exists($requested, autoload: false) && !interface_exists($requested, autoload: false)) {
            return null;
        }

        foreach ($this->ancestorsByDistance($requested) as $level) {
            $matches = array_values(array_intersect($level, array_keys($this->factories)));

            if ($matches === []) {
                continue;
            }

            if (count($matches) > 1) {
                /** @var non-empty-list<class-string> $matches */
                throw AmbiguousDefaultFactory::between($requested, $matches);
            }

            return $this->factories[$matches[0]];
        }

        return null;
    }

    /**
     * The interfaces a type declares itself, without the ones it only gets
     * through its parent or through another interface it lists.
     * `getInterfaceNames()` returns the transitive closure, and a closure has
     * no distances in it — every interface in the hierarchy would look one step
     * away.
     *
     * @param \ReflectionClass<object> $reflection
     *
     * @return list<class-string>
     */
    private function directInterfaces(\ReflectionClass $reflection): array
    {
        $all = $reflection->getInterfaceNames();
        $parent = $reflection->getParentClass();
        $inherited = $parent === false ? [] : $parent->getInterfaceNames();

        foreach ($all as $name) {
            $inherited = [...$inherited, ...(new \ReflectionClass($name))->getInterfaceNames()];
        }

        return array_values(array_diff($all, $inherited));
    }

    /**
     * Ancestors of a type, one level at a time: the parent class and the
     * interfaces declared directly, then theirs, and so on. `class_parents()`
     * and `class_implements()` flatten the graph, and a flattened graph cannot
     * say which of two registrations is nearer.
     *
     * @param class-string $type
     *
     * @return list<list<class-string>>
     */
    private function ancestorsByDistance(string $type): array
    {
        $levels = [];
        $current = [$type];
        $seen = [$type => true];

        while ($current !== []) {
            $next = [];

            foreach ($current as $name) {
                $reflection = new \ReflectionClass($name);
                $parent = $reflection->getParentClass();

                $candidates = $parent === false
                    ? $this->directInterfaces($reflection)
                    : [$parent->getName(), ...$this->directInterfaces($reflection)];

                foreach ($candidates as $candidate) {
                    if (isset($seen[$candidate])) {
                        continue;
                    }

                    $seen[$candidate] = true;
                    $next[] = $candidate;
                }
            }

            if ($next !== []) {
                $levels[] = $next;
            }

            $current = $next;
        }

        return $levels;
    }
}
