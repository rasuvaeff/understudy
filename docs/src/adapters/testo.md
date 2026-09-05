---
title: Testo adapter
description: "rasuvaeff/understudy-testo — verify after success, reset always, and the reset that runs after your teardown."
---

# Testo adapter

<img src="/adapters/testo/logo-mark.svg" width="48" height="48" alt="" style="border-radius: 8px;" />

`rasuvaeff/understudy-testo` ends every plain [Testo](https://php-testo.github.io/)
test with understudy's bookkeeping done for you.

```bash
composer require --dev rasuvaeff/understudy-testo
```

## What it does

| | |
|---|---|
| **Verify after success** | after a passing body, every `expect()` is checked. An expectation the code under test never fulfilled turns the pass into a failure |
| **Original failure wins** | after a failing or skipped body nothing is verified, so the adapter can never mask the error that actually happened |
| **Reset always** | the context is dropped after every test, in `finally`. One test can never leak a double, an expectation or a stub into the next |

## Registering it

```php
// testo.php
use Rasuvaeff\Understudy\Testo\UnderstudyPlugin;
use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\SuiteConfig;

return new ApplicationConfig(
    src: ['src'],
    suites: [
        new SuiteConfig(
            name: 'Unit',
            location: ['tests'],
            plugins: [new UnderstudyPlugin()],
        ),
    ],
);
```

Then write tests as the core documents them — no manual cleanup:

```php
#[Test]
final class CheckoutTest
{
    public function chargesForTheCart(): void
    {
        $books = Understudy::for(BookRepository::class);
        expect(static fn () => $books->find(7))->returns($expected = new Book(7));

        $receipt = (new Checkout($books))->charge(cart: [7]);

        Assert::same($receipt->total, $expected->price);
    }
}
```

If the service never calls `find(7)`, the test fails after its body — with an
unmet-expectation report naming the call, not with a silent green.

## Strict stubs

```php
new UnderstudyPlugin(strictStubs: true)
```

Off by default. What it means, and how it differs from per-double strictness,
is on [Strict stubs](/guide/expectations/strict-stubs).

## Which tests are verified

Verification is for plain `#[Test]` tests. `#[TestInline]` cases and benchmarks
are **not** verified — an inline case is meant to be a pure, deterministic
table-driven check with no setup to answer for, and a benchmark would pay for
verification on every iteration. Keep doubles in plain tests.

The reset is not scoped that way: whatever the kind of test, its doubles are
dropped when it ends, so an inline case cannot hand a leftover to the test
after it.

## What gets recorded

On a passing test the verification counts as one more assertion: the
`assertions` metric goes up by one and an "expectations verified" record is
appended to the collected `TestState`. A verification failure is recorded the
same way and reported as the test's failure.

A test whose only check is an understudy expectation is **not** risky. Testo
calls a passing test risky when it recorded no assertion, and it decides that
before this adapter can contribute the verification — so the adapter takes the
verdict back when its own record is the only one in the history. Tests that
also assert on their own keep whatever verdict they earned, and **a test that
created no double is not touched at all**: nothing is recorded for it, its
assertion count is its own, and the runner's verdict stands.

::: warning One place it is not visible
The `assert-history` block Testo prints. The collector renders that text before
returning, and this adapter runs outside the collector, so the record does not
exist yet at rendering time. The count and the attached state carry it; the
printed history does not.
:::

## Fiber isolation

Core runtime contexts are fiber-local, so tests that suspend fibers keep their
doubles isolated. Verification is deliberately wider: `verifyAll()` and
`reset()` reach every context the test put doubles in, including one a
`#[RunInFiber]` body owns.

This interceptor never stands in that context, and before the core spanned them
an unmet `expect()` inside such a test passed silently. The adapter itself
copies and replaces no process state.

The semantics are on [Fiber isolation](/guide/lifecycle/fibers).

## The reset runs after your teardown

The interceptor's `finally` sits outside the lifecycle interceptor, and the
call log retains every returned value until that reset — so a value a double
returned is still referenced while your `#[AfterTest]` runs.

For a value that owns an OS resource this matters, and it has bitten a real
suite. See [Retention and lean()](/guide/lifecycle/retention).

Verification runs after your teardown here too — in the interceptor, outside
`#[AfterTest]` — while the [PHPUnit adapter](/adapters/phpunit) verifies in
`assertPostConditions()`, which PHPUnit calls *before* `tearDown()`. Neither is
wrong, but a test whose expectation is fulfilled by teardown itself passes here
and fails there.

## API

| Member | Purpose |
|---|---|
| `UnderstudyPlugin` | registers the interceptor on a suite; `strictStubs` off by default |
| `UnderstudyInterceptor` | verify-after-success, reset-in-`finally`; registered by the plugin |

Everything else — `for()`, `when()`, `expect()`, `verify()`, matchers,
forwarding, `wire()` — belongs to the engine and is documented in this guide.
This package adds no operations of its own.
