<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Defaults;

use Rasuvaeff\Understudy\Tests\Fixture\Cls\Stamp;
use Rasuvaeff\Understudy\Tests\Fixture\Suit;

/**
 * One parameter per default shape `DefaultRenderer` distinguishes.
 */
final class Rendered extends RenderedBase
{
    public const int LIMIT = 5;

    private const int HIDDEN = 7;

    public function scalar(int $a = 5): void {}

    public function nothing(?string $a = null): void {}

    public function selfConstant(int $a = self::LIMIT): void {}

    public function parentConstant(int $a = parent::BASE): void {}

    public function hiddenConstant(int $a = self::HIDDEN): void {}

    public function globalConstant(int $a = \PHP_INT_SIZE): void {}

    public function enumCase(Suit $a = Suit::Hearts): void {}

    public function enumsInArray(array $a = [Suit::Hearts, [Suit::Spades]]): void {}

    public function objectWithKeywords(object $a = new Stamp(self::LIMIT + parent::BASE)): void {}

    public function objectWithUpperCaseKeyword(object $a = new Stamp(parent::BASE)): void {}

    public function objectWithQuotedKeyword(object $a = new Stamp(self::LIMIT, 'self::LIMIT')): void {}

    public function objectInNestedArray(array $a = [[new Stamp(1)]]): void {}

    public function quotedNew(string $a = 'new Stamp'): void {}

    public function keywordAfterQuote(array $a = [new Stamp(0, 'abc'), self::LIMIT]): void {}

    public function counted(object $a = new Tally()): void {}
}
