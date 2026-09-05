---
title: Psalm plugin
description: "rasuvaeff/understudy-psalm — the matcher report dropped inside a specification closure and nowhere else, plus UnderstudyMisuse."
---

# Psalm plugin

`rasuvaeff/understudy-psalm` teaches Psalm the one thing about understudy that
looks wrong and is not.

```bash
composer require --dev rasuvaeff/understudy-psalm
vendor/bin/psalm-plugin enable rasuvaeff/understudy-psalm
```

This page covers the Psalm side. The shared reasoning — why a matcher is
declared `mixed`, and why the leak has to stay reported — is on
[Static analysis](/guide/static-analysis).

## The report it drops, and where

`Arg::int()` is declared `mixed`, because a matcher has to be passable wherever
the contract declares anything at all. The plugin drops Psalm's argument-type
report inside a specification closure, and **only** there:

| Where | Psalm |
|---|---|
| `when(fn () => $repo->find(Arg::int()))` | silent |
| `Understudy::expect(fn () => $repo->find(Arg::any()))` | silent |
| `Understudy::calls(fn () => $repo->find(Arg::any()))` | silent |
| `Understudy::verifySequence(fn () => …, fn () => …)` | silent |
| `$repo->find(Arg::int())` — a real call | **still an error** |

The last row is the point. A matcher reaching a real call raises `MatcherLeaked`
at run time, and a plugin that hid it would be worse than no plugin at all.

::: warning What reports that last row is Psalm, and it needs `errorLevel="1"`
The plugin suppresses; the report it leaves standing is Psalm's own
`MixedArgument`, which exists only at level 1. At levels 2 and above a leaked
matcher draws nothing here — the runtime `MatcherLeaked` catches it, one test
run later. The [PHPStan extension](/adapters/phpstan) has a rule of its own
(`understudy.matcherLeak`) and reports at every level; this plugin deliberately
does not, because a rule strict enough to catch a leak textually also misreads a
matcher that reaches its specification through a variable, a property or a
helper.
:::

## The two 0.4 idioms

- **`Arg::rest()`** legitimately passes fewer arguments than the contract
  declares, so `TooFewArguments` goes quiet on that call — but only when the
  call sits inside a specification **and** its last written argument is
  `Arg::rest()`. A real call ending in `Arg::rest()` is under-arity for real
  (the engine answers it with `ArgumentCountError`) and keeps both reports.
- **`Arg::captor()`**'s `$captor->capture()` is a matcher in method-call
  clothes: its argument-type report goes quiet inside a specification, it does
  not count against "exactly one call per closure", and a capture leaked into a
  real call keeps its report.

## What else it reports

| Reported as | For |
|---|---|
| `UnderstudyMisuse` | a matcher whose kind the parameter can never accept; a closure that specifies nothing, or two calls, or calls a static method; cardinality no run can satisfy; `verify()` arguments that contradict each other |
| Psalm's own `InvalidArgument` | `returns()` and `answers()` against the method being specified. The plugin does not check these — it fills in the builder's template parameter, and `WhenBuilder<TReturn>` already declares `returns(TReturn …)`. Psalm does the rest |
| Psalm's own array and method diagnostics | `wire()`, whose shape is read from the named class's constructor: an unknown key is an error, and each double is typed as its contract. A dynamic class-string is left alone |

## Silent when unsure

Everything the plugin is not sure about stays silent. A false accusation costs
more than a missed one here, because the engine still catches at run time what
static analysis misses.

## API

| Member | Purpose |
|---|---|
| `Plugin` | the entry point Psalm loads |
| `UnderstudyMisuse` | the issue type every finding of the plugin's own rules is reported under |

Everything else in the package is internal to the plugin.
