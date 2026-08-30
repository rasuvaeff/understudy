---
title: Retention and lean()
description: "What a transcript holds onto, the Windows-only teardown failure it produced, and the two ways out."
---

# Retention and lean()

`transcript()` retains every invocation until `reset()` or `checkpoint()`.
Retention covers **returned values** — and with the runner adapters, `reset()`
runs *after* your `#[AfterTest]` or `tearDown()`.

For plain data that is only memory. For a value that owns an OS resource — a
stream, a connection, a lock — the resource is still held while your teardown
runs.

## The failure this produced

A [forwarding](/guide/forwarding) double returned real file streams. Teardown's
directory removal failed with "Directory not empty" **on Windows only**,
because POSIX unlinks open files and every Linux run stayed green.

The [cookbook case](/cookbook/retention) has the runnable version.

## Two remedies

```php
Understudy::lean($double);                       // keep calls, not returned values
$result = Understudy::scope(fn () => ...);       // or drop the whole context early
```

### `lean()`

Keeps the invocation — method, arguments, sequence — so matching, `verify()`,
`transcript()` and [`nothingElse()`](/guide/expectations/nothing-else) work
unchanged. The returned value is not retained:

| | On a lean double |
|---|---|
| `Invocation::returned()` | raises `OutcomeUnavailable`, as it already does for a call that threw |
| The transcript line | shows `returned (value not kept: lean)` |
| Matching, `verify()`, `nothingElse()` | unchanged |

It is one-way for the double's lifetime, and it also caps the per-call memory
growth of a hot loop.

The one thing `lean()` cannot release is the stable slot behind a
by-reference (`&`) return — callers hold a live reference into it, and the
language requires the storage to outlive the call.

### `scope()`

Drops the whole context — outcomes included — before the lifecycle teardown
runs.

## The rule

A double whose returned values own OS resources should be built lean, or built
and used inside `Understudy::scope()`.
