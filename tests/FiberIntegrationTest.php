<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\Understudy\Tests\Fixture\BookRepository;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Fiber\RunInFiber;
use Testo\Test;

use function Rasuvaeff\Understudy\expect;

#[Test]
#[CoversNothing]
final class FiberIntegrationTest
{
    #[RunInFiber]
    public function theAdapterVisibleContextIncludesTheFiberBody(): void
    {
        $double = Understudy::for(BookRepository::class);

        expect(static fn() => $double->find(123));

        Assert::false(Understudy::idle());
    }
}
