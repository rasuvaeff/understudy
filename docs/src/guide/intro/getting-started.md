---
title: Getting started
description: "Install understudy, pick a runner adapter, and write the first stub and the first expectation."
---

# Getting started

## Requirements

- PHP 8.3 – 8.5
- `ext-tokenizer`

Nothing else. `ext-mbstring` is not needed: failure messages count characters
through PCRE, which cannot be disabled.

## Install

```bash
composer require --dev rasuvaeff/understudy
```

Then add the adapter for the runner your suite already uses. The engine works
without one — the adapter's job is to call verification and reset for you at
the right moment, so that a forgotten `verifyAll()` cannot turn a failing test
green.

::: code-group

```bash [Testo]
composer require --dev rasuvaeff/understudy-testo
```

```bash [PHPUnit or Pest]
composer require --dev rasuvaeff/understudy-phpunit
```

:::

See [Testo](/adapters/testo) and [PHPUnit](/adapters/phpunit) for the one-time
registration each needs, and [Using Pest](/guide/using-pest) for the `expect()`
name collision Pest introduces.

## The first double

```php
use Rasuvaeff\Understudy\Understudy;

$repository = Understudy::for(BookRepository::class);
```

`for()` returns the contract's own type, so your IDE and your static analyser
treat `$repository` as a `BookRepository` and nothing more. There are no extra
methods on it — see [Creating a double](/guide/doubles/creating) for combining
several interfaces and for what a class double does.

## The first stub

A stub says what a call answers:

```php
use function Rasuvaeff\Understudy\when;

when(fn () => $repository->find(123))->returns($book);
```

The closure makes the call. A rename of `find`, a wrong argument type, or a
missing argument is a type error before the test ever runs.

## The first expectation

An expectation says the call must happen:

```php
use function Rasuvaeff\Understudy\expect;

expect(fn () => $repository->save($book));   // exactly once

Understudy::verifyAll();
```

`expect()` states how often, `verifyAll()` checks it. With an adapter
installed, `verifyAll()` is called for you after the test body.

## Two rules that catch everyone once

**Arm before the run.** An `expect()` counts only the calls that arrive after
it is declared. An expectation armed after the subject has already run counts
zero and fails as "called never" about a call that did happen. To claim a call
that has already happened, use [`verify()`](/guide/expectations/verify)
instead — every double records every call, so verification never has to be set
up in advance.

**One registration per call.** A `when()` stub and an `expect()` naming the
exact same call do not compose, and the second one is refused at registration:

```php
when(fn () => $repository->find(7))->returns($book);
expect(fn () => $repository->find(7));       // ConflictingExpectation
```

```text
Understudy `BookRepository` already has `find(7)` stubbed, and a separate
expect() for the same call would not compose with it: the expectation would
take the dispatch and answer the mode default, silently discarding the stub.
Declare one expectation — expect(...)->returns(...), or count the stub with
when(...)->times(...).
```

Say both things in one registration instead — whichever reads better:

```php
expect(fn () => $repository->find(7))->returns($book);
// or
when(fn () => $repository->find(7))->returns($book)->times(1);
```

Overlap is not equality: a broad fallback stub underneath a narrower
expectation is still the documented layering. Only the *exact same* call is
refused.

## Putting it together

```php
public function chargesForTheCart(): void
{
    $books = Understudy::for(BookRepository::class);
    expect(fn () => $books->find(7))->returns($expected = new Book(7));

    $receipt = (new Checkout($books))->charge(cart: [7]);

    Assert::same($receipt->total, $expected->price);
}
```

One registration says both things: `find(7)` must be called exactly once, and
it answers `$expected`. If `Checkout` never calls it, the test fails after its
body with a report naming the call — not with a silent green.

## Where to go next

- [Concepts](/guide/intro/concepts) — the vocabulary the rest of these pages
  use precisely.
- [Stubbing](/guide/stubbing/index) — `returns`, `throws`, `answers`, and what
  a specification closure may contain.
- [Argument matchers](/guide/stubbing/matchers) — when the exact value is not
  the point.
- [Expectations](/guide/expectations/index) — counts, ranges and `verifyAll()`.
