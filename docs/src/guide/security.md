---
title: Security
description: "What understudy does with the code it doubles: generates a class, evaluates it once, and touches nothing else."
---

# Security

Understudy generates a class per set of contracts and evaluates it once per
process.

| | |
|---|---|
| Loads code from user input | never |
| Touches the filesystem | never |
| Holds state | in `WeakMap`s keyed by the double object |

The `WeakMap` keying is not an implementation detail worth skipping: state is
never keyed by `spl_object_id()`, which PHP reuses after collection. An id-keyed
registry would hand one double's registrations to an unrelated object that
happened to be allocated in the same slot.

::: danger It is a development dependency
Do not install it in production. `composer require --dev`.
:::

## The one thing that changes the process

[`bypassFinals()`](/guide/doubles/final-classes) rewrites source as it is read,
so a class really is not final any more for the rest of the process. It is
opt-in for exactly that reason, and it refuses to act rather than to compete
when another source transformer is already installed.
