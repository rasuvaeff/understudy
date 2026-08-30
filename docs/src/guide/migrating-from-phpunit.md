---
title: Migrating from PHPUnit
description: "createMock and createStub mapped verb by verb, the matcher translation, strict stubs, and the Prophecy tail."
---

# Migrating from PHPUnit

For suites using PHPUnit's own `createMock()` / `createStub()`. Verified
against PHPUnit 12.5.33; the mapping holds for 10 through 13.

::: tip Also rendered outside the site
This guide and [Migrating from Mockery](/guide/migrating-from-mockery) are the
same document as `MIGRATION.md` in the repository.
:::

## The mapping

| PHPUnit | Understudy |
|---|---|
| `createMock(BookRepository::class)` / `createStub(…)` | `Understudy::for(…)` |
| `->expects($this->once())->method('find')` | `expect(fn () => $repo->find(…))` |
| `$this->once()` / `never()` / `exactly(n)` | `times(1)` / `never: true` / `times(n)` |
| `$this->any()` | no `expect()` at all — use `when()` |
| `$this->atLeast(n)` | `times(minimum: n)` |
| `->with(123, $this->anything())` | in the closure: `find(123, Arg::any())` |
| `$this->equalTo($v)` | the literal `$v` |
| `$this->identicalTo($v)` | `Arg::same($v)` |
| `$this->isInstanceOf(Foo::class)` | `Arg::instanceOf(Foo::class)` |
| `$this->callback(fn ($x) => …)` | `Arg::satisfies(fn ($x) => …)` |
| `$this->isType('int')` | `Arg::int()` |
| `$this->stringContains($s)` | `Arg::string(matches: '/…/')` |
| `->willReturn($v)` | `->returns($v)` |
| `->willReturnCallback(fn …)` | `->answers(fn (Invocation $i) => …)` |
| `->willThrowException($e)` | `->throws($e)` |
| `->willReturn(1, 2)` / `willReturnOnConsecutiveCalls(1, 2)` | `->returns(1, 2)` — one form for both |
| `->willReturnSelf()` | `->returns($double)` |
| `->willReturnMap([...])` | several `when()` calls, or one `answers()` with a `match` |
| `->willReturnArgument(0)` | `->answers(fn (Invocation $i) => $i->args[0])` |
| strict about stubs | `verifyAll(strictStubs: true)` |
| `getMockForAbstractClass()` / `onlyMethods()` | a class double plus [`delegate()`](/guide/forwarding) |
| automatic reset between tests | the [PHPUnit adapter](/adapters/phpunit) trait |
| Pest's own `expect()` | `expect as expectCall`, or `Understudy::expect()` |

## Three differences that are not one-to-one

### `willReturnMap()` has no single equivalent

PHPUnit's map is a table of argument-lists to return values. Understudy says
the same thing as several stubs, which reads better and analyses better:

```php
when(fn () => $repo->find(1))->returns($a);
when(fn () => $repo->find(2))->returns($b);
when(fn () => $repo->find(Arg::any()))->returns(null);   // the fallback
```

A later stub wins, and earlier ones remain reachable for arguments the later
one does not match — so declare the broad fallback **first** or give it a
non-overlapping matcher. See [Stubbing](/guide/stubbing/index#which-stub-wins).

Where the mapping is genuinely tabular, one `answers()` with a `match` is
closer to the original.

### `expects()` is both count and behaviour; here they can be separate

```php
// PHPUnit
$repo->expects($this->once())->method('find')->with(7)->willReturn($book);

// Understudy — one registration, both things
expect(fn () => $repo->find(7))->returns($book);
```

An expectation needs no `returns()` at all: the [mode](/guide/modes)'s default
supplies the value. That is why `$this->any()` translates to *no* `expect()` —
a call you do not count is a [stub](/guide/stubbing/index), and writing it as
an expectation with an unbounded range says something the test does not mean.

### Partial doubles

`getMockForAbstractClass()` and `onlyMethods()` produce an object where the
un-named methods run for real. The understudy shape is a class double in
[forwarding](/guide/forwarding) mode:

```php
$service = Understudy::delegate(Checkout::class, new Checkout($deps));
when(fn () => $service->rate())->returns(0.2);   // this one is stubbed
```

Everything not stubbed runs for real **and is recorded**, which the PHPUnit
form does not give you.

## Two rules PHPUnit does not have

**Arm before the run.** `expect()` counts only calls arriving after it is
declared. PHPUnit's `expects()` is also declared up front, so a straight
translation is fine — but a test that had moved its assertions to the end will
need the expectation moved back to the top, or converted to
[`verify()`](/guide/expectations/verify).

**One registration per call.** A `when()` and an `expect()` for the exact same
call are refused with `ConflictingExpectation`. PHPUnit allows a stub and a
separate expectation on one mock, so this is the shape most likely to need
rewriting:

```php
// was: a stub for the value, plus an expectation for the count
expect(fn () => $repo->find(7))->returns($book)->times(2);   // now: one registration
```

## Strict stubs

PHPUnit 10+ can be configured to fail on a stub that was never used.
Understudy's equivalent is
[`verifyAll(strictStubs: true)`](/guide/expectations/strict-stubs), set once on
a base test case.

## Coming from Prophecy

PHPUnit 10 dropped Prophecy from the box, so this is a common second hop.

| Prophecy | Understudy |
|---|---|
| `$this->prophesize(Foo::class)` | `Understudy::for(Foo::class)` |
| `$prophecy->reveal()` | nothing — `for()` already returns the double |
| `->willReturn($v)` | `->returns($v)` |
| `->willThrow($e)` | `->throws($e)` |
| `Argument::any()` / `type()` / `that()` | `Arg::any()` / `Arg::instanceOf()` / `Arg::satisfies()` |
| `->shouldBeCalled()` | `expect(…)` |
| `->shouldBeCalledTimes(n)` | `expect(…)->times(n)` |
| `->shouldNotBeCalled()` | `expect(…)->never()` |
| `->shouldHaveBeenCalled()` | [`verify(…)`](/guide/expectations/verify) |
| `$prophet->checkPredictions()` | `Understudy::verifyAll()`, or the adapter |

The structural difference: Prophecy separates the prophecy object from the
revealed double. Understudy has one object, and the specification is a call
made on it — so there is no reveal step and no pair of variables to keep
straight.
