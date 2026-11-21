<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy;

/**
 * Switches strict stubs — `verifyAll(strictStubs: true)`, where a stub
 * configured but never called fails the test — for one test class or one test
 * method:
 *
 * ```php
 * #[StrictStubs]
 * final class CheckoutTest
 * {
 *     #[StrictStubs(false)]
 *     public function legacyPath(): void {}
 * }
 * ```
 *
 * The engine only declares it; a runner bridge reads it through
 * {@see self::resolve()} and passes the answer to `verifyAll()`. One attribute
 * for every bridge, so a test reads the same under each of them.
 *
 * The method wins over its class, the nearest class in the hierarchy over a
 * more distant one, and any attribute over the bridge's own default.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class StrictStubs
{
    public function __construct(public bool $enabled = true) {}

    /**
     * Whether strict stubs apply to a test: the method's attribute, else the
     * nearest class carrying one — the test class first, then its parents —
     * else `$default`, which is the bridge's own setting.
     *
     * A method that does not exist on the class is not an error: the class
     * level still answers. A bridge may ask before it knows the method.
     *
     * @param class-string          $class
     * @param non-empty-string|null $method
     */
    public static function resolve(string $class, ?string $method = null, bool $default = false): bool
    {
        $reflection = new \ReflectionClass($class);

        if ($method !== null && $reflection->hasMethod($method)) {
            $declared = self::declaredOn($reflection->getMethod($method));

            if ($declared !== null) {
                return $declared;
            }
        }

        for ($current = $reflection; $current instanceof \ReflectionClass; $current = $current->getParentClass()) {
            $declared = self::declaredOn($current);

            if ($declared !== null) {
                return $declared;
            }
        }

        return $default;
    }

    /**
     * @param \ReflectionClass<object>|\ReflectionMethod $owner
     */
    private static function declaredOn(\ReflectionClass|\ReflectionMethod $owner): ?bool
    {
        $attributes = $owner->getAttributes(self::class);

        return $attributes === [] ? null : $attributes[0]->newInstance()->enabled;
    }
}
