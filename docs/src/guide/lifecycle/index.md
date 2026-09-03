---
title: Phases, scopes and transcripts
description: "checkpoint(), scope(), transcript() and the call log — what owns a set of doubles and when it is cleared."
---

# Phases, scopes and transcripts

```php
Understudy::checkpoint();                       // verify, then forget what is settled
$result = Understudy::scope(fn () => ...);      // nested context, verified on success
echo Understudy::transcript($repository);       // every call and its outcome
Understudy::idle();                             // true when the context holds no doubles
```

## The context

A [context](/guide/intro/concepts#contexts-and-fibers) owns doubles, their
registrations and their transcripts.

- `checkpoint()` keeps the understudies, their modes and their labels while
  clearing what the current phase has settled. Useful when one test walks
  through several stages and each stage has its own claims.
- `scope()` opens a nested context, runs the callback, and drops the context
  either way. It returns whatever the callback returns, and a failure inside is
  never replaced by a teardown error. On success it verifies the context it
  opened, and only that one: the enclosing context is still running, so a claim
  the test has yet to satisfy does not fail a self-contained scope. The test as
  a whole is answered for by `verifyAll()`, `checkpoint()` and the runner
  adapter's teardown.

A double created in a scope is invalid after that scope closes.

::: warning Configuration and verification belong to the owner
They must run in the context that owns the double. Ordinary calls may be made
from another fiber and are still recorded in the owner's log — see
[Fiber isolation](/guide/lifecycle/fibers).
:::

## Reading the call log

```php
use Rasuvaeff\Understudy\Arg;

$calls = Understudy::calls(fn () => $repository->find(Arg::any()));

$calls[0]->args;          // [123]
$calls[0]->didReturn();   // true
$calls[0]->returned();    // the value it answered with
$calls[1]->thrown();      // the throwable, if it threw
```

`null` is a valid return value, which is why the outcome is **asked about**
(`didReturn()`) rather than inferred from the value.

```php
$last = Understudy::lastCall(fn () => $repository->find(Arg::any()));

$last?->args;   // the newest matching call, null when there was none
```

`lastCall()` is the null-safe replacement for `count($calls) - 1`: an empty log
has no last element, and static analysis cannot prove otherwise, so the index
arithmetic reports `int<-1, max>` before the test even runs.

## The transcript

`transcript()` renders every call and its outcome, and retains every invocation
until `reset()` or `checkpoint()`.

Avoid unbounded hot loops through a double when the arguments or results hold
large object graphs; use a real fake for load-sized workloads. Where the
retained thing owns an OS resource rather than memory, see
[Retention and lean()](/guide/lifecycle/retention) — that is a correctness
problem, not a size one.

## Cleaning up

```php
Understudy::reset();
Understudy::idle();   // true when the current context holds no doubles
```

The [Testo](/adapters/testo) and [PHPUnit](/adapters/phpunit) adapters verify
and reset for you after every test. Without one, call `reset()` in your own
teardown.
