<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Expectation;

use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Expectation\Expectation;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * An expectation read back next to a call it refused, with every position that
 * did not accept the call marked. Asked while a refusal is being built, inside
 * the code under test, so nothing here may throw whatever the matchers do.
 */
#[Test]
#[Covers(Expectation::class)]
final class ExpectationDescribeAgainstTest
{
    public function aTailMatcherJudgesEverythingFromItsPosition(): void
    {
        $rest = new Expectation('find', [Arg::any(), Arg::rest()]);
        $none = new Expectation('find', [Arg::any(), Arg::none()]);

        Assert::same($rest->describeAgainst([1, 2, 3]), 'find(any(), rest())');
        Assert::same($rest->describeAgainst([1]), 'find(any(), rest())');
        Assert::same($none->describeAgainst([1]), 'find(any(), none())');
        Assert::same($none->describeAgainst([1, 2]), 'find(any(), *none()*)');
    }

    public function anArgumentTheCallNeverCarriedIsMarked(): void
    {
        $expectation = new Expectation('find', [1, 2]);

        Assert::same($expectation->describeAgainst([1]), 'find(1, *2*)');
        Assert::same($expectation->describeAgainst([]), 'find(*1*, *2*)');
        Assert::same($expectation->describeAgainst([1, 2]), 'find(1, 2)');
        Assert::same($expectation->describeAgainst([1, 3]), 'find(1, *2*)');
    }

    public function aGuardThatThrowsCountsAsOneThatRefused(): void
    {
        $expectation = new Expectation('find', [Arg::any()]);
        $expectation->setGuard(static fn(): never => throw new \RuntimeException('boom'), 'id is valid');

        Assert::same($expectation->describeAgainst([1]), 'find(any()) *id is valid*');
    }

    public function aGuardThatAcceptsIsNotMarked(): void
    {
        $expectation = new Expectation('find', [Arg::any()]);
        $expectation->setGuard(static fn(): bool => true, 'id is valid');

        Assert::same($expectation->describeAgainst([1]), 'find(any()) id is valid');
    }
}
