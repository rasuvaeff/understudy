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

## Eight things worth knowing first

- **A class double runs the real bodies on itself.** The state the real
  instance held is copied onto the double when `forwarding()` is called, and an
  unmatched call executes the parent implementation with `$this` still the
  double — so a self-call inside the real code re-enters the double: it meets
  stubs, an armed protocol and the call log, and every call is recorded, the
  boundary one and the self-calls alike. Two consequences to hold on to:
  `static::class` inside a real body names the generated class rather than
  yours, and a `final` or `private` method is not overridden, so a self-call to
  one of those runs the real body directly — it works, but nothing intercepts
  or records it. The instance itself stops receiving the calls: after
  `forwarding()` it holds the state it had at that moment, and assertions
  belong on the double. The copy is shallow, which is the reach the instance
  already had — an object-valued property is the same object on both sides, so
  writes *through* one still land on the donor's collaborators.
- **An interface double proxies instead.** It has no bodies to reach: the call
  runs on the real instance, only the call at the boundary is recorded, and
  self-calls happen inside the real object where nothing can see them.
- **Running inline needs the instance to be exactly the doubled class.** A
  donor that is a *subclass* carries overrides the doubled class's own bodies
  do not contain, and running those bodies in their place would change which
  code executes — such a double keeps the proxy form, where the overrides answer.
  An inline double also cannot be re-pointed at a second instance: its state
  was copied from the first, a `readonly` property cannot be written twice, and
  a second copy would discard everything the real code has written since. Build
  a second double for the other instance.
- **A `: never` method reaches the real implementation.** Its throw lives
  there, and a forwarding double has something that can answer for itself.
- **An understudy is not a valid target.** Forwarding to one — itself included
  — sends every call back into a dispatcher, and an unmatched one keeps coming
  back until the stack runs out.
- **A by-reference argument is the caller's variable.** A forwarded method
  writes to it, and the call log keeps both readings — what was passed and what
  it became — so a verification still sees the value the caller handed over.
- **A fluent method comes back as the double.** When the real code returns
  `$this`, that already *is* the double — the parent body ran on it — so a
  chain stays doubled by itself. A `clone $this` works too: the copy is
  adopted while the body runs and inherits this double's forwarding, so a
  wither comes back as a working double — carrying none of this one's stubs,
  the same rule a `clone $double` in the test follows. What is refused, with
  `OriginalReturnTypeViolation`, is a real body handing out a `new static`:
  that object is of the generated class, so the return type accepts it, but no
  context knows it and every call on it would fail as an unregistered
  double — under the proxy the same shape was refused as a foreign instance.
- **`callOriginal()` follows the double's own form.** On an inline double it
  runs the parent body on the double, over the state the double holds — the
  donor's copy stopped being the current one at the first inline call. On every
  other double it runs on the real instance, where self-calls stay inside it.

::: warning Retention
A forwarding double returns whatever the real object returns, including values
that own an OS resource. Those stay referenced until the context is cleared,
which with the runner adapters happens after your teardown. See
[Retention and lean()](/guide/lifecycle/retention) — this is the exact shape
that produced a Windows-only teardown failure.
:::
