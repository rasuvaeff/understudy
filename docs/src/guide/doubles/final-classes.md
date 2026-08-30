---
title: Doubling a final class
description: "What bypassFinals() does, the six limits that come with it, and the three things to prefer over it."
---

# Doubling a final class

A `final` class you do not own, no interface, and no way to change it — that is
what `bypassFinals()` is for:

```php
// In your test bootstrap, before the class is autoloaded.
Understudy::bypassFinals(FinalGate::class);   // one class
Understudy::bypassFinals();                   // every class this process loads
```

It is opt-in because the technique has limits that are better met knowingly
than discovered.

## The limits

| | |
|---|---|
| Order matters | it works only for a class not yet read from disk; a class is read once per process |
| The process is changed | the class really is not final any more, so reflection in your test sees something production does not |
| `final` methods stay | a final method cannot be overridden either way, so a class carrying one is still refused |
| PHAR and preloaded classes | their source arrives as `phar://`, or before any bootstrap ran, so it never passes through the `file://` wrapper |
| The opcode cache is not a way back | however warm the cache is, and whether or not it holds the bypassed file — Linux keeps it out, Windows does not — the class stays open. Not being cached is a cost where it happens, not a guarantee to rely on |
| Another source transformer | if something else is already rewriting PHP source, understudy refuses rather than replacing it silently; a wrapper that leaves source alone composes and is accepted |

## The refusal tells you which one it was

When a class is still final at `for()`, the message says which case applies —
bypass never asked for, asked for other classes but not this one, or asked for
and out of reach — rather than sending you to check the thing that is already
right.

## Prefer these first

In order:

1. **Double an interface the class implements.** The usual answer, and the one
   that leaves the process alone.
2. **For a value object, build a real one.** A double of a thing with no
   collaborators buys nothing.
3. **Introduce an interface.** Where the class is yours to change.

Bypass is the answer when none of those is available.
