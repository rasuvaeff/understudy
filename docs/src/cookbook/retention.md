---
title: A double that held a file handle
description: "The call log retained a real stream past teardown, and only Windows noticed."
---

# A double that held a file handle

The failure was "Directory not empty" during teardown, **on Windows only**.
Every Linux run was green, because POSIX unlinks open files and Windows does
not.

The open file was held by the call log.

## What was happening

A [forwarding](/guide/forwarding) double returned real file streams. The
transcript retains what a call returned until `reset()` — and with a
[runner adapter](/adapters/testo), `reset()` runs **after** your `tearDown()`
or `#[AfterTest]`.

So the teardown that removed the directory ran while the stream was still
referenced.

```php
$store = Understudy::for(AttachmentStore::class);
when(fn () => $store->open('report.csv'))->answers(
    fn () => fopen('php://temp', 'rb+'),
);

$handle = $store->open('report.csv');
unset($handle);   // the caller let go — the call log did not
```

## What the log holds

<!-- case-study-output: retention -->
```text
retained: resource (stream)
transcript: Understudy `AttachmentStore` received 1 call(s):
  #1 open('report.csv') -> returned (value not kept: lean)

Call to `open()` returned, but the value was not kept: the understudy is lean (Understudy::lean()). Drop lean() to read outcomes, or observe the value inside answers().
```

The first line is the ordinary double: the stream is still there after the
caller dropped it. The rest is the same double built **lean** — the call is
still recorded, the value is not, and asking for it says exactly why.

## The fix

```php
Understudy::lean($store);                      // keep calls, not returned values
$result = Understudy::scope(fn () => ...);     // or drop the whole context early
```

`lean()` keeps the invocation — method, arguments, sequence — so matching,
`verify()`, `transcript()` and `nothingElse()` work unchanged. Only the
returned value goes.

`scope()` drops the whole context, outcomes included, before the lifecycle
teardown runs.

## The rule

A double whose returned values own an OS resource — a stream, a connection, a
lock — should be built lean, or built and used inside `Understudy::scope()`.

For plain data, retention is only memory, and the thing to avoid is an
unbounded hot loop through a double whose arguments hold large object graphs.
See [Retention and lean()](/guide/lifecycle/retention).
