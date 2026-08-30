---
title: Expectations
description: "expect() with times(), verifyAll(), and why an expectation needs no answer of its own."
---

# Expectations

An expectation says a call must happen, and how often. It is a claim, checked
at verification.

```php
use function Rasuvaeff\Understudy\expect;

expect(fn () => $repository->save($book));            // exactly once
expect(fn () => $repository->count())->times(1, 3);   // a range

Understudy::verifyAll();
```

With a [runner adapter](/adapters/testo) installed, `verifyAll()` is called for
you after the test body — a forgotten call cannot turn a failing test green.

## Counts

| Form | Means |
|---|---|
| `expect(…)` | exactly once |
| `expect(…)->times(3)` | exactly three times |
| `expect(…)->times(0)` | not at all |
| `expect(…)->times(1, 3)` | between one and three |
| `expect(…)->times(2, null)` | at least twice, no upper bound |

::: warning One argument means "exactly"
`times()` reads the number of arguments it was **given**, so a single argument
is an exact count however it is spelled. `times(minimum: 2)` is `times(2)` —
exactly twice — not an open range. Pass the second argument explicitly for
that: `times(2, null)`.
:::

Declare a repeated count **once**, with `times()`. Two separate `expect()`
registrations for the same call are refused rather than added together — see
below.

## An expectation needs no `returns()`

Counting and answering are separate concerns. Without one, the
[mode](/guide/modes)'s type-safe default supplies the value, and a matched
expectation satisfies even a strict double, because the call was expected.

When the call also needs an answer, say both things in one registration:

```php
expect(fn () => $repository->find(7))->returns($book);
```

## Arm before the run

An `expect()` counts only the calls that arrive **after** it is declared. An
expectation armed after the subject has run counts zero and fails as "called
never" about a call that did happen.

To claim a call that has already happened, use
[`verify()`](/guide/expectations/verify) instead.

## One registration per call

A `when()` stub and an `expect()` naming the exact same call do not compose,
and the second is refused at registration with `ConflictingExpectation`.
Whichever was declared later would take the dispatch and silently void the
other — a stub's answer replaced by the mode default, or a count starved and
then reported as "called never" about a call that did happen.

Overlap is not equality: a broad fallback stub underneath a narrower
expectation is still the documented layering.

## Next

- [Verifying after the fact](/guide/expectations/verify) — claiming calls that
  already happened.
- [Has everything been described?](/guide/expectations/nothing-else) —
  `nothingElse()` and `allVerified()`.
- [Call order](/guide/expectations/ordering) — `ordered()`,
  `verifySequence()`, `expectSequence()`.
- [Strict stubs](/guide/expectations/strict-stubs) — failing on a stub nobody
  used.
