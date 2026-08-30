---
title: Defaults registry
description: "Understudy::defaults() — saying what an unconfigured return of a given type should be, and how the nearest registration is chosen."
---

# Defaults registry

A nested double of `LoggerInterface` answers everything with a default and
tells the test nothing. A `NullLogger` is usually what it wanted:

```php
Understudy::defaults(LoggerInterface::class, fn () => new NullLogger());
Understudy::defaults(ClockInterface::class, fn () => FakeClock::frozen());
```

## Nullable returns

A registration outranks `null`. A method declared `?ClockInterface` answers
with the registered clock, because saying what the type should be means it
there too.

Without a registration, such a method is still `null`.

## Which registration wins

The nearest one, measured as distance in the type graph:

1. an exact match;
2. then the closest registered ancestor.

Two ancestors the same distance away raise `AmbiguousDefaultFactory` rather
than letting whichever was registered first decide — a tie has no order a
reader could predict.

A factory that produces the wrong type raises `InvalidDefaultValue`.

## Scope

Registrations belong to the current [context](/guide/lifecycle/index). Sibling
fibers do not see each other's, and `Understudy::reset()` drops them with the
test.

::: tip
Register them in a per-test fixture rather than once for a whole suite. A
registry that outlives the test it was written for is a shared mutable default,
and the failure it produces reads as if the wrong test was at fault.
:::
