<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\Understudy\Exception\ForgottenDouble;
use Rasuvaeff\Understudy\Runtime\Runtime;
use Rasuvaeff\Understudy\Tests\Fixture\BookRepository;
use Rasuvaeff\Understudy\Understudy;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

/**
 * Entry points the generated classes call, reached by hand with a double the
 * context no longer knows.
 */
#[Test]
#[Covers(Runtime::class)]
#[Covers(ForgottenDouble::class)]
final class RuntimeDirectTest
{
    #[AfterTest]
    public function tearDown(): void
    {
        Understudy::reset();
    }

    public function callingTheOriginalOnADoubleFromBeforeAResetNamesTheMethod(): void
    {
        $double = Understudy::for(BookRepository::class);
        Understudy::reset();

        Expect::exception(ForgottenDouble::class)->withMessageContaining('but `find()` was called on it');

        Runtime::callOriginal($double, 'find', [1]);
    }
}
