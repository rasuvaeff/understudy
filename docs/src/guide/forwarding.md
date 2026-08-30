---
title: Forwarding to a real object
description: "delegate(), forwarding mode and callOriginal() — a stub wins, everything else runs for real and is recorded."
---

# Forwarding to a real object

```php
$real = $container->get(CacheInterface::class);
$spy = Understudy::for(CacheInterface::class);
Understudy::forwarding($spy, $real);

when(fn () => $spy->get('key'))->throws(new PoolOverload());
```

Everything the test did not configure runs for real and is recorded; `get('key')`
throws. The target has to satisfy every contract the double stands in for, or
it is refused.

## Three ways to say it

| | Builds | Delegation |
|---|---|---|
| `Understudy::forwarding($double, $real)` | nothing — takes a double you have | on |
| `Understudy::delegate(Contract::class, $real)` | the double | on |
| `Understudy::for($real)` | a double of that object's class, remembering the object | **off** until `Understudy::forwarding($double)` |

`delegate()` is the pair in one expression. `for($real)` is the shorthand for a
non-final class — wrapping something is not the same as delegating to it, so it
keeps answering with defaults until you turn delegation on. A final class is
refused there: its class is already loaded, so the double cannot keep the
concrete type you are holding.

## Calling through from inside an answer

```php
when(fn () => $spy->get('key'))
    ->answers(fn (Invocation $call) => strtoupper((string) $call->callOriginal()));
```

## Five things worth knowing first

- **Only the call at the boundary is recorded.** If the real method calls
  another method on itself, that happens inside the real object. Understudy
  proxies an object; it does not instrument one.
- **A `: never` method reaches the real implementation.** Its throw lives
  there, and a forwarding double has something that can answer for itself.
- **An understudy is not a valid target.** Forwarding to one — itself included
  — sends every call back into a dispatcher, and an unmatched one keeps coming
  back until the stack runs out.
- **A by-reference argument is the caller's variable.** A forwarded method
  writes to it, and the call log keeps both readings — what was passed and what
  it became — so a verification still sees the value the caller handed over.
- **A fluent method comes back as the double.** When the real instance returns
  itself, the double is returned instead, so a chain stays doubled. A `static`
  method that returns a *different* instance of the real class is refused —
  that object is not a double, and returning it would break the override's own
  `: static`.

::: warning Retention
A forwarding double returns whatever the real object returns, including values
that own an OS resource. Those stay referenced until the context is cleared,
which with the runner adapters happens after your teardown. See
[Retention and lean()](/guide/lifecycle/retention) — this is the exact shape
that produced a Windows-only teardown failure.
:::
