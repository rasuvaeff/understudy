---
title: Migrating from Mockery
description: "The verb-by-verb mapping, the four traps that survive a mechanical translation, and what deliberately does not carry over."
---

# Migrating from Mockery

No aliases and no converter. The table maps the verb you know to the shape
here, and the sections after it cover what a mechanical translation gets wrong.

::: tip Also rendered outside the site
This guide and [Migrating from PHPUnit](/guide/migrating-from-phpunit) are the
same document as `MIGRATION.md` in the repository, so a reader who never opens
the site still gets them.
:::

## The mapping

| Mockery | Understudy | Notes |
|---|---|---|
| `Mockery::mock(BookRepository::class)` | `Understudy::for(BookRepository::class)` | |
| `$mock->shouldReceive('find')` | `when(fn () => $mock->find(...))` | a real call; no method-name string |
| `->once()` / `->twice()` / `->times(3)` | `expect(fn () => …)->times(3)` | an `expect()` is checked by `verifyAll()` or the adapter |
| `->atLeast()->once()` | `expect(…)->times(1, null)` | |
| `->andReturn($book)` | `->returns($book)` | |
| `->andReturnUsing(fn …)` | `->answers(fn (Invocation $i) => …)` | arguments come from `$i->args` |
| `->andThrow(new NotFound())` | `->throws(new NotFound())` | |
| `->andReturn($a, $b)` | `->returns($a, $b)` | one per call, last repeats |
| `->with(123, Mockery::any())` | inside the closure: `find(123, Arg::any())` | |
| `Mockery::on(fn ($x) => …)` | `Arg::satisfies(fn ($x) => …)` | |
| `Mockery::type(Foo::class)` | `Arg::instanceOf(Foo::class)` | |
| `->withAnyArgs()` | inside the closure: `method(Arg::rest())` | also the escape from spelling a wide signature: `method('svc', Arg::rest())` |
| `Mockery::capture($x)` | `Arg::captor(X::class)` + `$captor->capture()` | `last()` / `all()` are typed through the class-string — no `instanceof` at the read site |
| `$mock->shouldNotHaveReceived('save')` | `Understudy::unused($mock)` | or `verify(…, never: true)` for one call |
| `$mock->shouldHaveReceived('save')` | `verify(fn () => $mock->save(…))` | after the fact |
| `->ordered()` | `expect(…)->ordered()` | or `verifySequence()` / `expectSequence()` for a whole protocol |
| `->makePartial()` / `Mockery::spy($real)` | `Understudy::delegate(Contract::class, $real)` + stubs on top | a stub wins, everything else runs for real — and is recorded |
| `Mockery::close()` | the adapter's `reset()`, or your own teardown | |

## The traps

These four survive a mechanical translation and change what the test asserts.

### `shouldReceive()->once()` used as setup

```php
// Mockery: often written just to make the call answer something
$mock->shouldReceive('find')->once()->andReturn($book);
```

If all you needed was a value, the translation is `when(…)->returns($book)`,
not `expect(…)`. A `when()` is **permission**; an `expect()` is a claim. Moving
incidental setup across as an expectation turns arrangement into an assertion,
and the test starts failing for reasons it never meant to check.

Translate to `expect()` only where the count was the point.

### A spy that counted every call

```php
// Mockery
$spy->shouldHaveReceived('save')->times(2);
```

`expect()` counts only the calls matching **its** arguments. Without
`nothingElse()`, a second call with different arguments passes:

```php
expect(fn () => $repo->save($expected))->times(2);
Understudy::nothingElse($repo);        // ← do not lose this
```

A hand-rolled counter caught the stray call; the migration must not lose it.
The [cookbook case](/cookbook/spy-counter) has the runnable version.

### `Mockery::close()` was global; contexts are not

Mockery's container is global to the process, and `close()` tears it down.
Understudy holds one [context per fiber](/guide/lifecycle/fibers), and a
[runner adapter](/adapters/testo) resets it after every test.

If you are porting a suite that called `Mockery::close()` in `tearDown()`,
install the adapter and delete the call rather than replacing it with
`Understudy::reset()` — the adapter's reset runs in `finally`, so it also
covers the tests that failed.

### A stub and an expectation for the same call

Mockery lets you keep adding to one `shouldReceive` chain. Understudy refuses a
second registration naming the exact same call:

```php
when(fn () => $repo->find(7))->returns($book);
expect(fn () => $repo->find(7));       // ConflictingExpectation
```

Say both things in one registration: `expect(…)->returns(…)`, or
`when(…)->times(…)`. See
[Getting started](/guide/intro/getting-started#two-rules-that-catch-everyone-once).

## What does not carry over

### `alias:` and `overload:`

```php
Mockery::mock('alias:App\Registry');
Mockery::mock('overload:App\Mailer');
```

Understudy does not patch statics or replace classes at load time. There is no
equivalent, deliberately — the technique changes the process for everything
that runs after it.

The replacement is an interface plus [`wire()`](/guide/wiring): give the
collaborator a contract, take it through the constructor, and double it.
Where the class is final and not yours to change,
[`bypassFinals()`](/guide/doubles/final-classes) is the narrow escape, and it
covers a class — not a static call.

### Method-name strings anywhere

There is no `shouldReceive('find')` form to fall back to. That is the whole
point of the library, and it is why a rename now breaks compilation rather than
matching.

### Localised failure messages

Understudy's messages are English only. They are also
[structured data](/guide/failure-messages#reading-a-failure-as-data) if you are
building a reporter.

## Teardown semantics side by side

| | Mockery | Understudy |
|---|---|---|
| State lives in | a process-global container | one context per fiber |
| Torn down by | `Mockery::close()` | the adapter, in `finally` |
| After a failing test | `close()` still verifies | nothing is verified — the original failure wins |
| Between fibers | shared | isolated |

The third row is worth a second read. Understudy never verifies after a failing
body, so the adapter cannot mask the error that actually happened.
