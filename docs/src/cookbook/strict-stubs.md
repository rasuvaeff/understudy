---
title: The stub nobody used
description: "A stub describing a call the subject stopped making — invisible by default, and usually the bug."
---

# The stub nobody used

A stub is permission, so an unused one is silent by default. That is right on
the day the test is written and wrong a year later, when the stub describes a
call the code **stopped** making.

## The test

```php
$repository = Understudy::for(BookRepository::class);

when(fn () => $repository->find(7))->returns(new Book('Dune'));

// This one described a call the subject stopped making when the lookup moved
// to find(). Nothing fails by default: a stub is permission.
when(fn () => $repository->findBySlug('dune'))->returns(new Book('Dune'));

$repository->find(7);

Understudy::verifyAll();
```

## What it reports

With `strictStubs` turned on:

<!-- case-study-output: strict-stubs -->
```text
verifyAll() alone: passed

Understudy `BookRepository` has a stub for `findBySlug('dune')` that was never used.
Remove it, or drop strictStubs if the call is genuinely optional.
```

## Why this was the bug

The lookup moved from `findBySlug()` to `find()`, and the test was updated by
**adding** a stub rather than replacing one. Both stubs sat there; the subject
used one. The test kept passing, and it kept describing a collaborator that no
longer had that shape.

That is the signal strict stubs buys: not "you wrote a stub you did not need",
but "this test still believes something about the code that stopped being
true".

## Turning it on

Project-wide, on a base class or in the plugin — not per test. See
[Strict stubs](/guide/expectations/strict-stubs) for each runner, and for why
it is off by default.

::: tip Not the same as a strict double
`verifyAll(strictStubs: true)` is about registrations nobody used.
`Understudy::strict($double)` is a [mode](/guide/modes), about calls nothing
configured accepted — and it fails at the call, not at verification.
:::
