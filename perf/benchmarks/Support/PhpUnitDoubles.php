<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Perf\Support;

use PHPUnit\Framework\Constraint\Constraint;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Rule\InvocationOrder;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * `createMock()` and `createStub()` are protected members of `TestCase` — the
 * only way to measure what a PHPUnit user actually writes is from inside a
 * subclass. Going through `MockGenerator` directly would skip the registration
 * and event emission that `createMock()` performs, which is real work PHPUnit
 * does on every double.
 *
 * A fresh instance stands for a fresh test: `createMock()` registers every
 * double it hands out with the test case, so one long-lived instance would
 * accumulate them and measure a leak nobody has.
 */
final class PhpUnitDoubles extends TestCase
{
    /**
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return MockObject&T
     */
    public function mock(string $type): MockObject
    {
        return $this->createMock($type);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return Stub&T
     */
    public function stub(string $type): Stub
    {
        return self::createStub($type);
    }

    /**
     * `once()` is a protected *instance* method and `anything()` a protected
     * static one; both are final, so the wrappers cannot reuse their names.
     */
    public function exactlyOnce(): InvocationOrder
    {
        return $this->once();
    }

    public static function anyArgument(): Constraint
    {
        return self::anything();
    }
}
