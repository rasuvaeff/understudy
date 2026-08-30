---
title: Verifying after the fact
description: "verify() and unused() — claiming calls that already happened, with no setup in advance."
---

# Verifying after the fact

Every double records every call, so verification never has to be set up in
advance:

```php
use function Rasuvaeff\Understudy\verify;

verify(fn () => $repository->save($book));                 // at least once
verify(fn () => $repository->save($book), times: 2);       // exactly twice
verify(fn () => $repository->save($book), minimum: 2);     // no upper bound
verify(fn () => $repository->ping(), never: true);

Understudy::unused($repository);                           // nothing at all
```

## `verify()` against `expect()`

| | `expect()` | `verify()` |
|---|---|---|
| When it is written | before the subject runs | after |
| What it counts | calls arriving after the declaration | calls already in the transcript |
| Default count | exactly once | at least once |
| Checked by | `verifyAll()` | itself, immediately |

The default count differs on purpose. An expectation written up front is a
design statement, and "exactly once" is the useful default for one. A
verification written after the fact is an observation, and "it happened" is
usually the question being asked.

## Reading the log directly

When the question is about the arguments rather than the count:

```php
use Rasuvaeff\Understudy\Arg;

$calls = Understudy::calls(fn () => $repository->find(Arg::any()));

$calls[0]->args;          // [123]
$calls[0]->didReturn();   // true
```

For a typed read of one argument, prefer
[`Arg::captor()`](/guide/stubbing/capturing) over indexing into `args`.

## Verification accounts for a call

A **successful** `verify()` marks the calls it claimed as accounted for, which
is what [`nothingElse()`](/guide/expectations/nothing-else) then checks against.
A failed `verify()` accounts for nothing.
