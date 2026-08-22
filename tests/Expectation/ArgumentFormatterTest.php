<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Expectation;

use Rasuvaeff\Understudy\Expectation\ArgumentFormatter;
use Rasuvaeff\Understudy\Tests\Fixture\Book;
use Rasuvaeff\Understudy\Tests\Fixture\Suit;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(ArgumentFormatter::class)]
final class ArgumentFormatterTest
{
    #[DataProvider('scalarProvider')]
    public function rendersScalars(mixed $value, string $expected): void
    {
        Assert::same(ArgumentFormatter::format($value), $expected);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function scalarProvider(): iterable
    {
        yield 'null' => [null, 'null'];
        yield 'true' => [true, 'true'];
        yield 'false' => [false, 'false'];
        yield 'int' => [42, '42'];
        yield 'float' => [1.5, '1.5'];
        // Quoting is what tells the reader '1' from 1 in a failure message.
        yield 'string' => ['alpha', "'alpha'"];
        yield 'numeric string' => ['1', "'1'"];
        yield 'empty array' => [[], '[]'];
    }

    public function truncatesALongString(): void
    {
        $rendered = ArgumentFormatter::format(str_repeat('a', 100));

        Assert::string($rendered)->contains('…');
        Assert::true(mb_strlen($rendered) < 100);
    }

    public function rendersAListInline(): void
    {
        Assert::same(ArgumentFormatter::format([1, 2, 3]), '[1, 2, 3]');
    }

    public function rendersAMapWithItsKeys(): void
    {
        Assert::same(ArgumentFormatter::format(['topic' => 'orders']), "['topic' => 'orders']");
    }

    public function elidesALongArray(): void
    {
        Assert::string(ArgumentFormatter::format([1, 2, 3, 4, 5, 6, 7]))->contains('…');
    }

    public function rendersAnObjectByClassName(): void
    {
        Assert::same(ArgumentFormatter::format(new Book('Dune')), Book::class);
    }

    #[DataProvider('escapeProvider')]
    public function escapesWhatWouldBreakTheLine(string $value, string $expected): void
    {
        // A failure message renders each argument on one line; a raw newline
        // or quote would hide what actually differed.
        Assert::same(ArgumentFormatter::format($value), $expected);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function escapeProvider(): iterable
    {
        yield 'single quote' => ["it's", "'it\\'s'"];
        yield 'backslash' => ['a\\b', "'a\\\\b'"];
        yield 'newline' => ["a\nb", "'a\\nb'"];
        yield 'carriage return' => ["a\rb", "'a\\rb'"];
        yield 'tab' => ["a\tb", "'a\\tb'"];
    }

    public function boundsDeeplyNestedArrays(): void
    {
        // Deep structures belong in a debugger, not in a failure message.
        $deep = [1, [2, [3, [4, [5, [6]]]]]];

        $rendered = ArgumentFormatter::format($deep);

        Assert::string($rendered)->contains('…');
        Assert::false(str_contains($rendered, '6'));
    }

    public function keepsShallowNestingReadable(): void
    {
        Assert::same(ArgumentFormatter::format([1, [2, 3]]), '[1, [2, 3]]');
    }

    public function rendersAnEnumCaseByName(): void
    {
        Assert::same(ArgumentFormatter::format(Suit::Hearts), Suit::class . '::Hearts');
    }
}
