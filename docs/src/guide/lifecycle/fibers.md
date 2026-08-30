---
title: Fiber isolation
description: "One context per fiber rather than one static bag per process, and the false pass that closes."
---

# Fiber isolation

Understudy keeps **one context per fiber**, not one static bag per process.

This is the canonical page for the behaviour. The [Testo adapter](/adapters/testo)
describes what it does about it and links here.

## What the split buys

Each fiber gets its own recording phase, call log and sequence counter. Two
fibers exercising the same double do not interleave into one sequence, so a
protocol claim made in one is not broken by traffic from the other.

## What stays whole

Isolation and accounting are different things:

| | Per fiber | Across every context the test used |
|---|---|---|
| Recording phase, call log, sequence counter | yes | |
| `verifyAll()`, `reset()`, `idle()`, `checkpoint()` | | yes |

A body that runs in a fiber is still the test's, and an adapter asks about the
test from wherever it stands.

## The failure this closes

Without it, a test body run inside a fiber could leave an unmet `expect()`
behind while the suite stayed green — a false **pass**, which is the one
failure mode a verification library must not have.

It is not free: the accounting that makes it work costs roughly 0.3µs on every
double built, and that trade is recorded with its numbers in
[Performance](/guide/performance). The trade was reviewed and accepted rather
than absorbed quietly.

## The rule to remember

Configuration and verification must run in the context that **owns** the
double. Ordinary calls may be made from another fiber and are still recorded in
the owner's log.

A double created inside `Understudy::scope()` is invalid after that scope
closes — the same rule, with the nesting made explicit.
