<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Codegen;

use Rasuvaeff\Understudy\Codegen\DefaultRenderer;
use Rasuvaeff\Understudy\Tests\Fixture\Defaults\Rendered;
use Rasuvaeff\Understudy\Tests\Fixture\Defaults\Tally;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * The comparison key itself, one default shape per case: the unifier only
 * reaches it when two targets share a position, so most shapes are never
 * rendered through it.
 */
#[Test]
#[Covers(DefaultRenderer::class)]
final class DefaultRendererTest
{
    /**
     * @param non-empty-string $method
     */
    #[DataProvider('shapeProvider')]
    public function rendersEachShape(string $method, string $expected): void
    {
        $parameter = new \ReflectionParameter([Rendered::class, $method], 0);

        Assert::same(DefaultRenderer::render($parameter, $method), $expected);
    }

    /**
     * @return iterable<string, array{non-empty-string, string}>
     */
    public static function shapeProvider(): iterable
    {
        $fixture = '\\Rasuvaeff\\Understudy\\Tests\\Fixture\\';
        $rendered = $fixture . 'Defaults\\Rendered';
        $base = $fixture . 'Defaults\\RenderedBase';
        $stamp = $fixture . 'Cls\\Stamp';
        $suit = $fixture . 'Suit';

        yield 'a scalar by value' => ['scalar', '5'];
        yield 'null in lower case' => ['nothing', 'null'];
        yield 'self:: through the declaring class' => ['selfConstant', $rendered . '::LIMIT'];
        yield 'parent:: through the parent' => ['parentConstant', $base . '::BASE'];
        yield 'a private constant by value' => ['hiddenConstant', '7'];
        yield 'a global constant by value' => ['globalConstant', (string) \PHP_INT_SIZE];
        yield 'an enum case as itself' => ['enumCase', $suit . '::Hearts'];
        yield 'enum cases in nested arrays by value' => [
            'enumsInArray',
            var_export([\Rasuvaeff\Understudy\Tests\Fixture\Suit::Hearts, [\Rasuvaeff\Understudy\Tests\Fixture\Suit::Spades]], return: true),
        ];
        yield 'an object with both keywords qualified' => [
            'objectWithKeywords',
            'new ' . $stamp . '(' . $rendered . '::LIMIT + ' . $base . '::BASE)',
        ];
        yield 'a keyword in another case' => ['objectWithUpperCaseKeyword', 'new ' . $stamp . '(' . $base . '::BASE)'];
        yield 'a keyword inside quotes is left alone' => [
            'objectWithQuotedKeyword',
            'new ' . $stamp . '(' . $rendered . "::LIMIT, 'self::LIMIT')",
        ];
        yield 'an object deep in an array by source' => ['objectInNestedArray', '[[new ' . $stamp . '(1)]]'];
        yield 'new inside quotes is a string' => ['quotedNew', "'new Stamp'"];
        yield 'a keyword after a quoted run keeps its offset' => [
            'keywordAfterQuote',
            '[new ' . $stamp . "(0, 'abc'), " . $rendered . '::LIMIT]',
        ];
    }

    public function anObjectDefaultIsNeverEvaluated(): void
    {
        $before = Tally::$built;

        $rendered = DefaultRenderer::render(new \ReflectionParameter([Rendered::class, 'counted'], 0), 'counted');

        Assert::same($rendered, 'new \\' . Tally::class . '()');
        Assert::same(Tally::$built, $before);
    }
}
