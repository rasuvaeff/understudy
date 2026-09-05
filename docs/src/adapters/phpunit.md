---
title: PHPUnit adapter
description: "rasuvaeff/understudy-phpunit — verification and reset through a trait, under PHPUnit and Pest alike."
---

# PHPUnit adapter

<img src="/adapters/phpunit/logo-mark.svg" width="48" height="48" alt="" style="border-radius: 8px;" />

`rasuvaeff/understudy-phpunit` ends every test with understudy's bookkeeping
done for you, through a trait.

```bash
composer require --dev rasuvaeff/understudy-phpunit
```

Pest works too — it runs on PHPUnit, so the same trait applies through
`uses()`. See [Using Pest](/guide/using-pest).

## What it does

| | |
|---|---|
| **Verify after success** | after a body that reaches `assertPostConditions()`, every `expect()` is checked. An expectation the code never fulfilled fails the test as an assertion failure |
| **Original failure wins** | after a failing body nothing is verified, so the adapter can never mask the error that actually happened |
| **Reset always** | an `#[After]` hook drops the context unconditionally. One test can never leak a double into the next |
| **Early guard** | a `#[Before]` hook refuses to start over a context some earlier test left behind, which is what broken integration looks like — or one that `setUpBeforeClass()` filled: the context lives for one test, so create doubles in `setUp()` |

## Using it

```php
use function Rasuvaeff\Understudy\expect;

use PHPUnit\Framework\TestCase;
use Rasuvaeff\Understudy\PhpUnit\UnderstudyPHPUnitIntegration;
use Rasuvaeff\Understudy\Understudy;

final class CheckoutTest extends TestCase
{
    use UnderstudyPHPUnitIntegration;

    public function testChargesForTheCart(): void
    {
        $books = Understudy::for(BookRepositoryInterface::class);
        expect(fn () => $books->find(7))->returns($expected = new Book(7));

        $receipt = (new Checkout($books))->charge([7]);

        self::assertSame($expected->price, $receipt->total);
    }
}
```

One registration says both things: `find(7)` must be called exactly once, and
it answers `$expected`. If `Checkout` never calls it, the test fails after its
body with a report naming the call.

::: warning Arm it before the run
`expect()` counts only calls that arrive after it is declared, and it cannot be
paired with a `when()` for the same call. Both rules are on
[Getting started](/guide/intro/getting-started#two-rules-that-catch-everyone-once).
:::

## Strict stubs

A base class can flip strictness for a whole project:

```php
abstract class ProjectTestCase extends TestCase
{
    use UnderstudyPHPUnitIntegration;

    protected function understudyStrictStubs(): bool
    {
        return true;
    }
}
```

See [Strict stubs](/guide/expectations/strict-stubs).

**Verification runs before your teardown here, and after it under
[Testo](/adapters/testo).** `assertPostConditions()` is called by PHPUnit
*before* `tearDown()`; the Testo interceptor runs outside `#[AfterTest]`.
Neither is wrong, but a test whose expectation is fulfilled by teardown itself
fails here and passes there. `reset()` runs after teardown in both.

A test that creates no double is not touched at all: nothing is counted for it,
so `#[DoesNotPerformAssertions]` keeps meaning what it says.

## Overriding `assertPostConditions()`

PHP resolves a method-name conflict between a class and a trait **silently** in
favour of the class — the trait's verification would stop running without any
error at all. Compose explicitly:

```php
use Rasuvaeff\Understudy\PhpUnit\UnderstudyPHPUnitIntegration {
    UnderstudyPHPUnitIntegration::assertPostConditions as understudyAssertPostConditions;
}

protected function assertPostConditions(): void
{
    // your post-conditions ...
    $this->understudyAssertPostConditions();
}
```

The trait runs `parent::assertPostConditions()` before verifying, so your own
post-conditions always run and their failure is reported ahead of an unmet
expectation — the check closer to the test body wins. Keep that order in an
explicit composition too.

## The reset runs after your teardown

PHPUnit invokes `#[After]` hooks once `tearDown()` has finished, and the call
log retains every returned value until that reset. For a returned value that
owns an OS resource this matters — see
[Retention and lean()](/guide/lifecycle/retention).

## API

| Member | Purpose |
|---|---|
| `UnderstudyPHPUnitIntegration` | the trait: verify-after-success, reset via `#[After]`, `#[Before]` guard, optional project-wide strict stubs |

Everything else belongs to the engine and is documented in this guide. This
package adds no operations of its own.
