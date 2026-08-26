<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Expectation;

use Rasuvaeff\Understudy\Expectation\ArgumentFormatter;
use Rasuvaeff\Understudy\Tests\Fixture\Book;
use Rasuvaeff\Understudy\Tests\Fixture\Draft;
use Rasuvaeff\Understudy\Tests\Fixture\Order;
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
        Assert::true(strlen($rendered) < 100);
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

    public function rendersAnObjectAsAnAliasAndItsPublicState(): void
    {
        // The alias answers "was it another instance", the properties answer
        // "was it shaped differently" — the two reasons a `===` match fails.
        Assert::same(ArgumentFormatter::format(new Book('Dune')), Book::class . "#1 {title: 'Dune'}");
    }

    public function twoInstancesOfOneClassGetDistinctAliases(): void
    {
        $first = new Book('Dune');
        $second = new Book('Dune');

        Assert::same(
            ArgumentFormatter::format([$first, $second, $first]),
            sprintf(
                "[%s#1 {title: 'Dune'}, %s#2 {title: 'Dune'}, %s#1 {title: 'Dune'}]",
                Book::class,
                Book::class,
                Book::class,
            ),
        );
    }

    public function aliasesAreNumberedPerMessageAndNotPerProcess(): void
    {
        // Object ids are reused after a collection; a message that printed one
        // would read differently on the next run of the same failing test.
        $rendered = ArgumentFormatter::format(new Book('Dune'));

        Assert::same(ArgumentFormatter::format(new Book('Herbert')), Book::class . "#1 {title: 'Herbert'}");
        Assert::same($rendered, Book::class . "#1 {title: 'Dune'}");
    }

    public function oneScopeSpansEverythingRenderedInsideIt(): void
    {
        $book = new Book('Dune');

        Assert::same(
            ArgumentFormatter::scope(static fn(): string => ArgumentFormatter::format(new Book('Herbert'))
                . ' ' . ArgumentFormatter::format($book)
                . ' ' . ArgumentFormatter::format($book)),
            sprintf("%s#1 {title: 'Herbert'} %s#2 {title: 'Dune'} %s#2 {title: 'Dune'}", Book::class, Book::class, Book::class),
        );
    }

    public function anObjectWithNothingPublicRendersAsItsAliasAlone(): void
    {
        // `Order` keeps its state private behind getters, and a getter is user
        // code: rendering a message must not run any.
        Assert::same(ArgumentFormatter::format(new Order()), Order::class . '#1');
    }

    public function anUninitializedTypedPropertyIsNotReadToRenderIt(): void
    {
        // Reading it would throw, or reach `__get()`; `get_object_vars()` from
        // outside the class simply does not list it.
        Assert::same(ArgumentFormatter::format(new Draft()), Draft::class . '#1');
    }

    public function anObjectPropertyIsRenderedWithinTheSameDepthBudget(): void
    {
        $book = new Book('Dune');

        Assert::same(
            ArgumentFormatter::format([[[$book]]]),
            sprintf('[[[%s#1 {title: …}]]]', Book::class),
        );
        Assert::same(ArgumentFormatter::format([[$book]]), sprintf("[[%s#1 {title: 'Dune'}]]", Book::class));
    }

    public function aLongPropertyListIsCutLikeALongArray(): void
    {
        $wide = new \stdClass();

        foreach (range(1, 7) as $index) {
            $wide->{'p' . $index} = $index;
        }

        Assert::same(
            ArgumentFormatter::format($wide),
            'stdClass#1 {p1: 1, p2: 2, p3: 3, p4: 4, p5: 5, …}',
        );
    }

    public function exactlyFivePropertiesAreRenderedWhole(): void
    {
        $wide = new \stdClass();

        foreach (range(1, 5) as $index) {
            $wide->{'p' . $index} = $index;
        }

        Assert::same(ArgumentFormatter::format($wide), 'stdClass#1 {p1: 1, p2: 2, p3: 3, p4: 4, p5: 5}');
    }

    #[DataProvider('propertyNameProvider')]
    public function rendersAPropertyNameAsItReadsBack(string|int $name, string $expected): void
    {
        // `json_decode()` fills a stdClass from whatever the payload said, so a
        // property name is not always an identifier.
        $object = new \stdClass();
        $object->{(string) $name} = 1;

        Assert::same(ArgumentFormatter::format($object), 'stdClass#1 {' . $expected . ': 1}');
    }

    /**
     * @return iterable<string, array{string|int, string}>
     */
    public static function propertyNameProvider(): iterable
    {
        yield 'identifier' => ['title', 'title'];
        yield 'leading underscore' => ['_id', '_id'];
        yield 'digits after the first character' => ['a1', 'a1'];
        yield 'numeric' => ['0', '0'];
        yield 'with a space' => ['a b', "'a b'"];
        yield 'starting with a digit' => ['1a', "'1a'"];
        yield 'empty' => ['', "''"];
        yield 'newline' => ["a\nb", "'a\\nb'"];
    }

    public function aVeryLongPropertyNameIsTruncatedLikeAString(): void
    {
        $object = new \stdClass();
        $object->{str_repeat('n', 41)} = 1;

        Assert::same(ArgumentFormatter::format($object), 'stdClass#1 {' . str_repeat('n', 40) . '…: 1}');
    }

    public function aCycleIsStoppedByTheDepthBudget(): void
    {
        // `$a->self = $a` is legal, and a renderer that followed it would not
        // return.
        $node = new \stdClass();
        $node->self = $node;

        Assert::same(ArgumentFormatter::format($node), 'stdClass#1 {self: stdClass#1 {self: stdClass#1 {self: stdClass#1 {self: …}}}}');
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

    public function aStringOfExactlyTheLimitIsNotTruncated(): void
    {
        $value = str_repeat('a', 40);

        Assert::same(ArgumentFormatter::format($value), "'" . $value . "'");
    }

    public function aLongerStringIsCutToTheLimitAndMarked(): void
    {
        Assert::same(ArgumentFormatter::format(str_repeat('a', 41)), "'" . str_repeat('a', 40) . "…'");
    }

    public function truncationCountsCharactersNotBytes(): void
    {
        // Cutting bytes would split a multibyte character and render mojibake
        // in the middle of a failure message.
        Assert::same(ArgumentFormatter::format(str_repeat('и', 41)), "'" . str_repeat('и', 40) . "…'");
    }

    public function aMultibyteStringUnderTheLimitSurvivesWhole(): void
    {
        // 21 characters, 42 bytes: measuring bytes would truncate a string
        // that fits.
        $value = str_repeat('и', 21);

        Assert::same(ArgumentFormatter::format($value), "'" . $value . "'");
    }

    public function aStringThatIsNotUtf8FallsBackToBytes(): void
    {
        // PCRE cannot count characters in bytes that are not UTF-8; the byte
        // fallback keeps a binary blob from flooding the failure message.
        Assert::same(
            ArgumentFormatter::format(str_repeat("\xFF", 50)),
            "'" . str_repeat("\xFF", 40) . "…'",
        );
    }

    public function aShortStringThatIsNotUtf8SurvivesWhole(): void
    {
        $value = "\xFF\xFE";

        Assert::same(ArgumentFormatter::format($value), "'" . $value . "'");
    }

    public function truncationKeepsTheStringsOwnBeginning(): void
    {
        // Every character differs, so cutting from the wrong offset shows.
        $value = substr(str_repeat('abcdefghij', 5), 0, 41);

        Assert::same(ArgumentFormatter::format($value), "'" . substr($value, 0, 40) . "…'");
    }

    public function anArrayOfExactlyFiveIsRenderedWhole(): void
    {
        Assert::same(ArgumentFormatter::format([1, 2, 3, 4, 5]), '[1, 2, 3, 4, 5]');
    }

    public function aLongerArrayKeepsTheFirstFiveEntries(): void
    {
        Assert::same(ArgumentFormatter::format([1, 2, 3, 4, 5, 6, 7]), '[1, 2, 3, 4, 5, …]');
    }

    public function nestingIsRenderedUpToTheLimitAndNoFurther(): void
    {
        Assert::same(ArgumentFormatter::format([[['x']]]), "[[['x']]]");
        Assert::same(ArgumentFormatter::format([[[['x']]]]), '[[[[…]]]]');
    }

    public function mapKeysAndValuesShareTheDepthBudget(): void
    {
        Assert::same(ArgumentFormatter::format([['k' => [1, [2]]]]), "[['k' => [1, […]]]]");
        Assert::same(ArgumentFormatter::format([[['k' => 1]]]), "[[['k' => 1]]]");
        Assert::same(ArgumentFormatter::format([[[['k' => 1]]]]), '[[[[… => …]]]]');
    }

    public function anUnprintableValueFallsBackToItsDebugType(): void
    {
        $handle = fopen('php://memory', 'rb');

        Assert::same(ArgumentFormatter::format($handle), 'resource (stream)');

        if (is_resource($handle)) {
            fclose($handle);
        }
    }

    public function rendersAnEnumCaseByName(): void
    {
        Assert::same(ArgumentFormatter::format(Suit::Hearts), Suit::class . '::Hearts');
    }
}
