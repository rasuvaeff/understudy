---
title: Stubbing
description: "when() with returns(), throws() and answers() — what a stub is, how several stubs for one call resolve, and what a specification closure may contain."
---

# Stubbing

A stub says what a call answers. It is permission, not a claim: a stub that
goes unused does not fail a test unless you ask for
[strict stubs](/guide/expectations/strict-stubs).

```php
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Invocation;

use function Rasuvaeff\Understudy\when;

when(fn () => $repository->find(123))->returns($book);
when(fn () => $repository->find(404))->throws(new NotFound());
when(fn () => $repository->find(Arg::any()))->answers(
    fn (Invocation $call) => new Book(title: (string) $call->args[0]),
);
```

| Verb | Answers with |
|---|---|
| `returns($value, …)` | the value; with several, one per call, and the last one repeats |
| `throws($exception)` | the exception, thrown at the call site |
| `answers(fn (Invocation $call) => …)` | whatever the callback computes from the actual call |

```php
// One value per call, then the last one repeats.
when(fn () => $repository->mode())->returns('fast', 'slow');
```

For a different answer per call in a longer sequence, see
[Chaining behaviour](/guide/stubbing/chaining).

## Which stub wins

A later stub for the same call wins. Earlier ones stay reachable as fallbacks
for calls whose arguments the later one does not match.

Two consequences worth holding on to:

- An exhausted call-count expectation keeps answering the matching call. When a
  broad fallback should take over later calls, give it a **non-overlapping**
  matcher rather than relying on exhaustion.
- Overlap is fine and documented; equality is not. A `when()` and an `expect()`
  naming the *exact same* call are refused at registration — see
  [Getting started](/guide/intro/getting-started#two-rules-that-catch-everyone-once).

## An expectation needs no answer

Counting and answering are separate concerns. `expect()` without `returns()` is
complete: the [mode](/guide/modes)'s type-safe default supplies the value, and
a matched expectation satisfies even a strict double, because the call was
expected.

## What belongs in a specification closure

Exactly one call, on a double, with the arguments you mean.

```php
when(fn () => $repository->find(123))->returns($book);   // yes
when(fn () => $repository->find(123)->title)->…          // no: reads a property off the result
```

The closure is a real call — see
[Concepts](/guide/intro/concepts#the-specification-closure-is-a-real-call).
The arguments are evaluated in the ordinary way, which is what makes
[matchers](/guide/stubbing/matchers) typed and what makes a matcher built but
never passed to a specification a reported error rather than a silent no-op.

## Next

- [Argument matchers](/guide/stubbing/matchers) — the `Arg::*` table.
- [Capturing arguments](/guide/stubbing/capturing) — reading a value the
  subject passed.
- [Chaining behaviour](/guide/stubbing/chaining) — a different answer per call.
- [Modes](/guide/modes) — what an *unmatched* call does.
