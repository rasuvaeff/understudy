---
title: Static analysis
description: "Why a specification closure needs analyser help, and what the Psalm plugin and the PHPStan extension do about it."
---

# Static analysis

A specification closure is a real call, which is what makes understudy typed at
all. It also means one thing an analyser sees is genuinely odd:

```php
when(fn () => $repository->find(Arg::int(min: 1)))->returns($book);
```

`Arg::int()` is declared `mixed`, because a matcher has to be passable wherever
a contract declares anything at all. Psalm and PHPStan both report that as an
argument-type error — correctly in general, wrongly here.

Two packages fix it, one per analyser:

| | |
|---|---|
| [`rasuvaeff/understudy-psalm`](/adapters/psalm) | a Psalm plugin |
| [`rasuvaeff/understudy-phpstan`](/adapters/phpstan) | a PHPStan extension |

They are independent of the runner adapters and of each other. Install whichever
analyser your project already runs.

## What both of them do

| | |
|---|---|
| A matcher fits whatever the contract declares | inside a specification closure, and **only** there |
| `Arg::rest()` may stop before the arity does | so the "too few arguments" report goes quiet on that call |
| `$captor->capture()` counts as a matcher | not as a second call in the closure |
| `returns()` is checked against the method being specified | the builder's template parameter is filled in from the closure |
| `wire()` has the shape of the class it wired | an unknown key is an error, and each double is typed as its contract |
| A specification that can never work is reported | see the table below |

## What is deliberately still an error

```php
$repository->find(Arg::int());   // a real call, not a specification
```

A matcher reaching a real call raises `MatcherLeaked` at run time, and an
analyser package that hid it would be worse than no package at all. Both report
it — PHPStan under the identifier `understudy.matcherLeak`.

Everything else around a specification keeps its reports too: a wrong argument
beside a matcher, a method the double does not have, the statements around the
closure.

## The findings

| Identifier (PHPStan) | Reported when |
|---|---|
| `understudy.closure` | the closure specifies nothing, makes more than one call, or calls a static method a double cannot intercept |
| `understudy.cardinality` | `times(5, 2)`, a negative bound, `verify(…, never: true, times: 3)`, `times` beside a `minimum` |
| `understudy.matcher` | a matcher whose kind the parameter can never accept: `Arg::int()` where a `string` is declared |
| `understudy.returns` | `returns()` on a method declared `void`, where no value is ever observed |
| `understudy.matcherLeak` | a matcher written outside a specification, where it reaches the code as a value |

Psalm reports the same family under one issue type, `UnderstudyMisuse`.

## Both are silent when unsure

A refined parameter type — `non-empty-string`, an int range — answers "maybe"
to its plain kind, and a matcher can produce a value that fits it, so nothing
is reported.

A false accusation costs more than a missed one here, because the engine still
catches at run time what static analysis misses.
