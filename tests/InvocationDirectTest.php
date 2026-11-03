<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\Understudy\Exception\OriginalCallUnavailable;
use Rasuvaeff\Understudy\Invocation;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

/**
 * An `Invocation` built by hand, without the dispatcher behind it.
 */
#[Test]
#[Covers(Invocation::class)]
#[Covers(OriginalCallUnavailable::class)]
final class InvocationDirectTest
{
    public function aCallWithoutADoubleCannotBeForwarded(): void
    {
        Expect::exception(OriginalCallUnavailable::class)->withMessage(
            "Understudy `understudy` has no real instance to delegate `find()` to.\n"
            . 'Give it one: Understudy::forwarding($double, $real) — calling the parent implementation on a '
            . 'double whose constructor never ran is not a safe substitute.',
        );

        (new Invocation('find', [1], 1))->callOriginal();
    }
}
