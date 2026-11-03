<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\Understudy\Exception\OutcomeUnavailable;
use Rasuvaeff\Understudy\Outcome;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(Outcome::class)]
#[Covers(OutcomeUnavailable::class)]
final class OutcomeTest
{
    public function aReturnedValueIsReadBack(): void
    {
        $outcome = Outcome::returnedValue(null);

        Assert::true($outcome->didReturn());
        Assert::false($outcome->didThrow());
        Assert::null($outcome->returned('find'));
        Assert::null($outcome->thrown());
    }

    public function aThrowableIsReadBackAndHasNoReturnValue(): void
    {
        $error = new \RuntimeException('boom');
        $outcome = Outcome::thrownError($error);

        Assert::false($outcome->didReturn());
        Assert::true($outcome->didThrow());
        Assert::same($outcome->thrown(), $error);

        Expect::exception(OutcomeUnavailable::class)->withMessage(
            'Call to `find()` threw RuntimeException and has no return value. Check didReturn() first, or read thrown().',
        );

        $outcome->returned('find');
    }
}
