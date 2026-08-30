---
title: What is understudy
description: "Test doubles specified by making the call instead of naming the method in a string, and what that buys over Mockery and PHPUnit's own mocks."
---

# What is understudy

Understudy is a test double library for PHP 8.3+ in which the call you
configure is a **real call**:

```php
when(fn () => $repository->find(123))->returns($book);
```

That closure is not a description of a call. It is the call, made against the
double, with the arguments you mean — and understudy records what happened
inside it and turns it into a specification.

## What the closure buys

A method name written as a string is invisible to every tool in the toolchain.
A method name written as a call is not.

| | Method-name string | The call itself |
|---|---|---|
| Rename the method in the contract | the test still compiles, and fails at run time — or worse, silently stops matching | the test stops compiling |
| Typo in the name | found when the test runs, if it is ever run | found by the type checker |
| IDE "find usages" / "go to definition" | needs a plugin that understands the library | works, because it is an ordinary call |
| Wrong argument type | found when the test runs | found by the type checker |
| Too few arguments | found when the test runs | found by the type checker |

The same applies to the arguments. `find(123)` passes an `int` to a method that
declares one; passing `'123'` is a type error at analysis time, not a
non-matching call at run time.

## No members on the double

`Understudy::for(BookRepository::class)` returns the contract's own type, and
nothing else. There is no `shouldReceive`, no `expects`, no `allows` — every
such method would be a name the doubled contract can no longer use, and a
contract that happens to declare `expects()` would collide with the library
rather than with your intent.

Everything the library offers is a free function or a static on `Understudy`:
`when()`, `expect()`, `verify()`, `Understudy::verifyAll()`. The double stays
exactly as wide as the thing it stands in for.

## Against the alternatives

| | Understudy | Mockery / PHPUnit / phpspec |
|---|---|---|
| Specifying a call | a real call in a closure | a method-name string |
| Members added to the double | none | `shouldReceive`, `expects`, `allows`, … |
| Test runner | any, through thin adapters | tied to PHPUnit or Pest, or none at all |
| Fibers | one context per fiber | shared static state |

The call-closure form is not new — it comes from [MockK](https://mockk.io)
(Kotlin), [FakeItEasy](https://fakeiteasy.github.io) and
[moq](https://github.com/moq/moq) (C#), and
[mocktail](https://pub.dev/packages/mocktail) (Dart). No PHP library had it.

## What understudy does not do

It does not patch static methods or replace classes at load time. There is no
equivalent of Mockery's `alias:` or `overload:` targets. A static call has no
double instance to own its state, so calling a static contract method on a
double raises `InvalidCallSpecification` rather than pretending to work. When
the code under test reaches for a static, the answer is an interface and
[`wire()`](/guide/wiring), not a runtime patch.

It also refuses some targets outright rather than doubling them badly — a
`final` class, an enum, a trait, an anonymous class. A double that cannot
intercept every method would run the target's real code against an object whose
constructor never ran, which is worse than not building it at all.
[Doubling a final class](/guide/doubles/final-classes) covers the one supported
way around that.

## Where to go next

- [Getting started](/guide/intro/getting-started) — install it and write the
  first stub and the first expectation.
- [Concepts](/guide/intro/concepts) — the six words the rest of this
  documentation uses precisely.
- [Migrating from Mockery](/guide/migrating-from-mockery) or
  [from PHPUnit](/guide/migrating-from-phpunit) if you have a suite already.
