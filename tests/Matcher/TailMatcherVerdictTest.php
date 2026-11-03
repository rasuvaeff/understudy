<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Matcher;

use Rasuvaeff\Understudy\Matcher\AnyRest;
use Rasuvaeff\Understudy\Matcher\AnyTail;
use Rasuvaeff\Understudy\Matcher\EmptyTail;
use Rasuvaeff\Understudy\Matcher\Unspelled;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The single-argument reading of the matchers that normally stand for a whole
 * tail. The specification rejects them anywhere but last before matching, so
 * the dispatcher never asks — which is exactly why the answer is written down
 * here rather than left to whatever the implementation happens to return.
 */
#[Test]
#[Covers(AnyRest::class)]
#[Covers(AnyTail::class)]
#[Covers(EmptyTail::class)]
#[Covers(Unspelled::class)]
final class TailMatcherVerdictTest
{
    public function restAndRemainingAcceptAnySingleArgument(): void
    {
        Assert::true((new AnyRest())->matches(null));
        Assert::true((new AnyRest())->matches('x'));
        Assert::true((new AnyTail())->matches(null));
        Assert::true((new AnyTail())->matches(0));
    }

    public function noneRefusesAnySingleArgument(): void
    {
        Assert::false((new EmptyTail())->matches(null));
        Assert::false((new EmptyTail())->matches('x'));
    }

    public function anUnspelledPositionAcceptsAnything(): void
    {
        Assert::true((new Unspelled())->matches(null));
        Assert::true((new Unspelled())->matches(argument: false));
    }

    public function eachTailMatcherSaysWhatItStandsFor(): void
    {
        Assert::true((new AnyRest())->matchesTail([1, 2]));
        Assert::true((new AnyRest())->matchesTail([]));
        Assert::true((new AnyTail())->matchesTail([1]));
        Assert::true((new EmptyTail())->matchesTail([]));
        Assert::false((new EmptyTail())->matchesTail([1]));
        Assert::same((new AnyRest())->describe(), 'rest()');
        Assert::same((new AnyTail())->describe(), 'remaining()');
        Assert::same((new EmptyTail())->describe(), 'none()');
        Assert::same((new Unspelled())->describe(), '…');
    }
}
