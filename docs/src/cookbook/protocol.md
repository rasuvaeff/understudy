---
title: A query between two ordered steps
description: "A read between two protocol steps is refused, and the refusal says what to do about it."
---

# A query between two ordered steps

`expectSequence()` arms a protocol before the subject runs, so an out-of-turn
call fails **at the call** rather than in teardown. The price is that a call
the protocol does not know about has to be declared.

## The test

```php
Understudy::expectSequence(
    fn () => $repository->begin(),
    fn () => $repository->save($book),
    fn () => $repository->commit(),
);

$repository->begin();
$repository->find(7);   // a read the protocol never heard of
```

## What it reports

<!-- case-study-output: protocol -->
```text
Understudy `BookRepository` is under an armed protocol and received a call that is neither a step nor configured.

The call was:
    find(7)

The protocol is:
    1. begin()
    2. save(Book#1 {title: 'Dune'})   <- due here
    3. commit()

Say it may happen — when(fn () => $double->find(...))->returns(...) — or make it a step.
```

## Why it cannot just be ignored

On a double the protocol names, a call is either the step due or something the
test configured. Anything else is refused.

The alternative would be to guess. A protocol that silently let unknown calls
through could not tell "not part of this" from "you got the order wrong", and
the difference would only surface in teardown — which is exactly what arming
exists to avoid.

## The fix

Whichever the read actually is:

```php
// It is incidental — say it may happen.
when(fn () => $repository->find(7))->returns($book);

// It is part of the protocol — make it a step.
Understudy::expectSequence(
    fn () => $repository->begin(),
    fn () => $repository->find(7),
    fn () => $repository->save($book),
    fn () => $repository->commit(),
);
```

::: warning A broad catch can swallow this
If the subject catches the refusal, the test still fails — in teardown, with
the step it stopped at. Arming is a claim as well as a guard. See
[Call order](/guide/expectations/ordering).
:::
