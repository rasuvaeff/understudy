---
title: Capturing arguments
description: "Arg::captor() — reading a value the subject passed, typed through the class-string, with no positional index and no instanceof."
---

# Capturing arguments

`Arg::captor()` is the typed replacement for reading `args[N]` out of the call
log: no positional index, no `mixed`, no `instanceof` narrowing ritual.

```php
$options = Arg::captor(DeliveryOptions::class);      // Captor<DeliveryOptions>

when(fn () => $store->temporaryUrl(Arg::any(), Arg::any(), $options->capture()))
    ->returns('https://…');

$subject->run();

$options->last()->downloadName;   // typed: DeliveryOptions
$options->all();                  // list<DeliveryOptions>, in call order
```

`capture()` goes where the argument to observe goes.

## How it matches

| Form | Matches like |
|---|---|
| `Arg::captor(SomeClass::class)` | `Arg::instanceOf(SomeClass::class)` |
| `Arg::captor()` | `Arg::any()` |

The value is recorded only once the **whole** specification matched the call.
A call that the other arguments rejected captures nothing — the captor holds
what the specification claimed, not everything that went past it.

## Where it works

In `when()`, `expect()` and `verify()` alike. A `verify()` captures from the
calls it just claimed, which is the Mockito reading.

::: warning Not inside a protocol step
A `capture()` inside an [`expectSequence()`](/guide/expectations/ordering) step
matches but does not record. Capture at declaration or at verification, not in
a protocol.
:::

## Reading it

| Call | On an empty captor |
|---|---|
| `last()` | raises `NothingCaptured` |
| `all()` | answers an empty list |

The asymmetry is deliberate: `last()` is asked when the test believes a call
happened, so silence there would be a false green; `all()` is asked to iterate,
where an empty list is a perfectly ordinary answer.

## Lifetime

Captured values live exactly as long as the call log. `reset()` and a closing
[`Understudy::scope()`](/guide/lifecycle/index) drop them, and the captor
object is then simply empty again.
