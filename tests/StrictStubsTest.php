<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\Understudy\StrictStubs;
use Rasuvaeff\Understudy\Tests\Fixture\Strict\RelaxedChild;
use Rasuvaeff\Understudy\Tests\Fixture\Strict\StrictBase;
use Rasuvaeff\Understudy\Tests\Fixture\Strict\StrictChild;
use Rasuvaeff\Understudy\Tests\Fixture\Strict\Unmarked;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * The precedence a bridge relies on: method over class, nearest class over a
 * parent, any attribute over the bridge's own default.
 */
#[Test]
#[Covers(StrictStubs::class)]
final class StrictStubsTest
{
    /**
     * @param class-string          $class
     * @param non-empty-string|null $method
     */
    #[DataProvider('resolveProvider')]
    public function resolvesByPrecedence(string $class, ?string $method, bool $default, bool $expected): void
    {
        Assert::same(StrictStubs::resolve($class, $method, $default), $expected);
    }

    /**
     * @return iterable<string, array{class-string, non-empty-string|null, bool, bool}>
     */
    public static function resolveProvider(): iterable
    {
        yield 'no attribute anywhere keeps the default off' => [Unmarked::class, 'plain', false, false];
        yield 'no attribute anywhere keeps the default on' => [Unmarked::class, 'plain', true, true];
        yield 'a parent class attribute is inherited' => [StrictChild::class, 'plain', false, true];
        yield 'a method inherited from the marked parent' => [StrictChild::class, 'inherited', false, true];
        yield 'the method wins over the inherited class' => [StrictChild::class, 'relaxed', true, false];
        yield 'the nearest class wins over its parent' => [RelaxedChild::class, 'plain', true, false];
        yield 'the method wins over its own class' => [RelaxedChild::class, 'strict', false, true];
        yield 'no method asks the class alone' => [RelaxedChild::class, null, true, false];
        yield 'an unknown method falls back to the class' => [StrictChild::class, 'missing', false, true];
        yield 'the declaring class itself' => [StrictBase::class, null, false, true];
    }

    public function enabledByDefault(): void
    {
        Assert::true((new StrictStubs())->enabled);
        Assert::false((new StrictStubs(enabled: false))->enabled);
    }
}
