---
title: Modes
description: "Loose, strict and forwarding — what an unconfigured call answers, and the one level of nested doubles a loose double will build."
---

# Modes

A mode decides what an **unmatched** call does — a call nothing configured
accepted.

| Mode | Unmatched call answers with |
|---|---|
| Loose (default) | a type-safe default: `null`, `0`, `''`, `[]`, an empty generator … |
| Strict — `Understudy::strict($double)` | an immediate failure naming the method, the call, and what did not accept it |
| Forwarding — `Understudy::forwarding($double, $real)` | whatever the real instance answers, recorded like any other call |

Strictness is per double, not per test. It is a different setting from
[strict stubs](/guide/expectations/strict-stubs), which is about registrations
nobody used.

## What loose will and will not invent

A loose double never invents a value by running someone else's constructor, and
never hands back an unconstructed instance of a real class.

What it *can* hand back is another understudy: a return type that can itself be
doubled becomes one, **one level deep**, which the same test can configure.
That double is a generated stand-in, not the target with its constructor
skipped.

One level, and no further. A double created this way refuses to produce another,
so `$a->b()->c()` says so rather than inventing a third collaborator the test
never asked for. Registering a factory for `C` is how you say you meant it —
see [Defaults registry](/guide/defaults).

Where no safe value exists, the double says so, and names the way out.

## Strict fails at the call

```text
Understudy `BookRepository` is strict and received an unexpected call to `tag()`.

The call was:
    tag('beta', 1)

Nothing configured for `tag` accepted it:
    tag(*string(matches: /^a/)*, *2*)

Configure it first: when(fn () => $double->tag(...))->returns(...)
```

The marks are read from the expectation's side: each one is an argument that
rejected this call. Everything configured for that method is listed, because a
stub that could never have matched is often exactly the one the test meant to
write. See [Failure messages](/guide/failure-messages).

## Forwarding

Covered in full on [Forwarding to a real object](/guide/forwarding) — it is a
mode, but it carries enough of its own rules to need a page.
