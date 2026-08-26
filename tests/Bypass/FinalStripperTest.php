<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Bypass;

use Rasuvaeff\Understudy\Bypass\FinalStripper;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * The decisions the transform makes, on strings rather than through a process.
 *
 * The wrapper's end-to-end claims are made by `BypassFinalsIntegrationTest`, which spawns a
 * process per scenario — necessary, because a class loads once, and invisible to
 * coverage for the same reason. What can be decided about a source file can be
 * decided here, where every branch is reachable and every mistake is visible.
 */
#[Test]
#[Covers(FinalStripper::class)]
final class FinalStripperTest
{
    /**
     * @param list<array{namespace: string, class: string}>|null $targets
     */
    #[DataProvider('sourceProvider')]
    public function aSourceIsTransformedAsDescribed(string $source, ?array $targets, string $expected): void
    {
        Assert::same(FinalStripper::strip($source, $targets), $expected);
    }

    /**
     * @return iterable<string, array{string, list<array{namespace: string, class: string}>|null, string}>
     */
    public static function sourceProvider(): iterable
    {
        yield 'the named class loses final' => [
            '<?php namespace App; final class Gate {}',
            [['namespace' => 'App', 'class' => 'Gate']],
            '<?php namespace App; class Gate {}',
        ];

        yield 'a class in another namespace keeps it' => [
            '<?php namespace Other; final class Gate {}',
            [['namespace' => 'App', 'class' => 'Gate']],
            '<?php namespace Other; final class Gate {}',
        ];

        yield 'a sibling in the same file keeps it' => [
            '<?php namespace App; final class Gate {} final class Wall {}',
            [['namespace' => 'App', 'class' => 'Gate']],
            '<?php namespace App; class Gate {} final class Wall {}',
        ];

        yield 'global mode opens every class' => [
            '<?php namespace App; final class Gate {} final class Wall {}',
            null,
            '<?php namespace App; class Gate {} class Wall {}',
        ];

        yield 'a final method is never touched' => [
            '<?php namespace App; final class Gate { final public function open(): void {} }',
            [['namespace' => 'App', 'class' => 'Gate']],
            '<?php namespace App; class Gate { final public function open(): void {} }',
        ];

        yield 'global mode leaves final methods alone too' => [
            '<?php namespace App; final class Gate { final public function open(): void {} }',
            null,
            '<?php namespace App; class Gate { final public function open(): void {} }',
        ];

        yield 'a final class constant is never touched' => [
            '<?php namespace App; class Gate { final public const string KEY = "k"; }',
            null,
            '<?php namespace App; class Gate { final public const string KEY = "k"; }',
        ];

        yield 'readonly survives between final and class' => [
            '<?php namespace App; final readonly class Money {}',
            [['namespace' => 'App', 'class' => 'Money']],
            '<?php namespace App; readonly class Money {}',
        ];

        yield 'the keyword is case-insensitive, like PHP' => [
            '<?php namespace App; FINAL class Gate {}',
            [['namespace' => 'App', 'class' => 'Gate']],
            '<?php namespace App; class Gate {}',
        ];

        yield 'the word inside a string is not a keyword' => [
            '<?php namespace App; class Gate { public string $note = "final class Gate"; }',
            null,
            '<?php namespace App; class Gate { public string $note = "final class Gate"; }',
        ];

        yield 'the word inside a comment is not a keyword' => [
            "<?php namespace App;\n// final class Gate\nclass Gate {}",
            null,
            "<?php namespace App;\n// final class Gate\nclass Gate {}",
        ];

        yield 'a doc comment between final and class does not hide the class' => [
            '<?php namespace App; final /** here */ class Gate {}',
            [['namespace' => 'App', 'class' => 'Gate']],
            '<?php namespace App; /** here */ class Gate {}',
        ];

        yield 'a newline between final and class is kept' => [
            "<?php namespace App; final\nclass Gate {}",
            [['namespace' => 'App', 'class' => 'Gate']],
            "<?php namespace App; \nclass Gate {}",
        ];

        yield 'a newline is kept in global mode too' => [
            "<?php namespace App;\nfinal\nclass Gate {}\nfinal\nclass Wall {}",
            null,
            "<?php namespace App;\n\nclass Gate {}\n\nclass Wall {}",
        ];

        yield 'a file without the word is returned untouched' => [
            '<?php namespace App; class Gate {}',
            null,
            '<?php namespace App; class Gate {}',
        ];

        yield 'a class in the global namespace is reached by an empty namespace' => [
            '<?php final class Gate {}',
            [['namespace' => '', 'class' => 'Gate']],
            '<?php class Gate {}',
        ];

        yield 'a braced namespace is read like any other' => [
            '<?php namespace App { final class Gate {} }',
            [['namespace' => 'App', 'class' => 'Gate']],
            '<?php namespace App { class Gate {} }',
        ];

        yield 'the second namespace in a file is its own scope' => [
            '<?php namespace One { final class Gate {} } namespace Two { final class Gate {} }',
            [['namespace' => 'Two', 'class' => 'Gate']],
            '<?php namespace One { final class Gate {} } namespace Two { class Gate {} }',
        ];

        yield 'an anonymous class is not a declaration to open' => [
            '<?php namespace App; $x = new class {}; final class Gate {}',
            [['namespace' => 'App', 'class' => 'Gate']],
            '<?php namespace App; $x = new class {}; class Gate {}',
        ];

        yield 'an enum keeps its shape' => [
            '<?php namespace App; enum Suit: string { case Hearts = "H"; } final class Gate {}',
            null,
            '<?php namespace App; enum Suit: string { case Hearts = "H"; } class Gate {}',
        ];

        yield 'an unnamed target list opens nothing' => [
            '<?php namespace App; final class Gate {}',
            [],
            '<?php namespace App; final class Gate {}',
        ];
    }
}
