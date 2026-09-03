---
title: Argument matchers
description: "The Arg::* table, why the type matchers are strict, and how rest() differs from remaining()."
---

# Argument matchers

A matcher goes where the argument goes:

```php
when(fn () => $repository->find(Arg::any()))->returns($book);
```

## The table

| Matcher | Matches |
|---|---|
| `Arg::any()` | anything, including `null` |
| `Arg::int(min:, max:)` | an `int` in range — a numeric string does not match |
| `Arg::float(min:, max:)` | a `float` in range — an `int` does not match |
| `Arg::string(matches:)` | a string, optionally against a PCRE pattern |
| `Arg::bool()` | a boolean |
| `Arg::same($v)` | strict identity; for objects, the same instance |
| `Arg::not($v)` | negates a literal or another matcher |
| `Arg::allOf(...)` | everything the operands accept; an operand is a matcher or a literal |
| `Arg::anyOf(...)` | anything at least one operand accepts, so `anyOf('draft', 'review')` reads as a set |
| `Arg::instanceOf($class)` | an instance of the class or interface |
| `Arg::satisfies($fn)` | whatever the predicate accepts |
| `Arg::containing($entries)` | an array holding these entries and possibly more |
| `Arg::count(minimum:, maximum:)` | an array or `Countable` of that size |
| `Arg::which($method, $value)` | an object whose getter answers this value |
| `Arg::none()` | an empty variadic tail — last argument only |
| `Arg::remaining()` | the whole variadic tail, any length — last argument only |
| `Arg::rest()` | declared parameters left unspelled — last argument only |

There is also [`Arg::captor()`](/guide/stubbing/capturing), which matches and
records at the same time.

## The type matchers are strict on purpose

`Arg::int()` rejects `'5'`. `Arg::float()` rejects `1`.

A matcher pins the declared type as much as the value, which is the point in a
codebase running under `strict_types`. A matcher that quietly accepted the
other type would hide exactly the bug the declaration exists to prevent.

## A matcher that could never match is refused

`Arg::int(min: 5, max: 1)` — and the same shape in `Arg::float()` and
`Arg::count()` — describes an empty range, and `Arg::string('/[unclosed')` is
not a pattern PCRE compiles. Both are typos, and both used to build a matcher
that answered "no" to every argument, so the mistake surfaced as an
expectation never met, with nothing pointing at its cause. They are refused
with `InvalidCallSpecification` where they are written.

The broken pattern had a second cost: `preg_match()` raises a warning on every
call, from inside the code under test — which is the one thing a matcher must
never do.

## `rest()` against `remaining()`

They look similar and stand for different things:

| | Stands for |
|---|---|
| `Arg::remaining()` | the variadic tail the method **declares** — any length |
| `Arg::rest()` | "the arguments written here matter, the rest of the arity does not" |

`rest()` is the one matcher that lets a specification stop before the method's
required parameters run out:

```php
when(fn () => $storage->recordOutcome('svc', Arg::rest()))
    ->throws(new RuntimeException('storage unavailable'));
```

A specification that stops early **without** ending in `Arg::rest()` is refused
with the reason, rather than becoming a stub that silently never matches. A
later, narrower specification for the same call still wins over the broad
prefix stub.

::: warning One thing to know
A static analyser reads the shortened call against the contract's arity, so
expect a "too few arguments" diagnostic on that line until your analyser knows
the idiom. The [Psalm plugin](/adapters/psalm) and the
[PHPStan extension](/adapters/phpstan) teach it.
:::

## `Arg::which()` and a getter that throws

`Arg::which()` calls only a public, non-static method that needs no arguments.

A getter that throws counts as a **mismatch**, never as an error. Matching runs
while the code under test is executing, and a matcher must not be the thing
that breaks it.

## A matcher that never reaches a specification

Building a matcher and not passing it to a specification is reported, not
ignored — `MatcherLeaked`. The static analysis packages report the same thing
before the test runs, under `understudy.matcherLeak`.
