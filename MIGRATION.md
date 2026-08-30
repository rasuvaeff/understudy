<!-- Generated from docs/src/guide/migrating-*.md by docs/scripts/render-migration.mjs.
     Edit those pages, then run `make docs-migration`. -->

# Migrating to understudy

Two guides, one per library you are coming from. Both are also published at
[https://rasuvaeff.github.io/understudy/](https://rasuvaeff.github.io/understudy/), where the cross-links resolve.

- [Migrating from Mockery](#migrating-from-mockery)
- [Migrating from PHPUnit](#migrating-from-phpunit)

---


# Migrating from Mockery

No aliases and no converter. The table maps the verb you know to the shape
here, and the sections after it cover what a mechanical translation gets wrong.

> **Also rendered outside the site**
>
> This guide and [Migrating from PHPUnit](#migrating-from-phpunit) are the
> same document as `MIGRATION.md` in the repository, so a reader who never opens
> the site still gets them.

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
The [cookbook case](https://rasuvaeff.github.io/understudy/cookbook/spy-counter) has the runnable version.

### `Mockery::close()` was global; contexts are not

Mockery's container is global to the process, and `close()` tears it down.
Understudy holds one [context per fiber](https://rasuvaeff.github.io/understudy/guide/lifecycle/fibers), and a
[runner adapter](https://rasuvaeff.github.io/understudy/adapters/testo) resets it after every test.

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
[Getting started](https://rasuvaeff.github.io/understudy/guide/intro/getting-started#two-rules-that-catch-everyone-once).

## What does not carry over

### `alias:` and `overload:`

```php
Mockery::mock('alias:App\Registry');
Mockery::mock('overload:App\Mailer');
```

Understudy does not patch statics or replace classes at load time. There is no
equivalent, deliberately — the technique changes the process for everything
that runs after it.

The replacement is an interface plus [`wire()`](https://rasuvaeff.github.io/understudy/guide/wiring): give the
collaborator a contract, take it through the constructor, and double it.
Where the class is final and not yours to change,
[`bypassFinals()`](https://rasuvaeff.github.io/understudy/guide/doubles/final-classes) is the narrow escape, and it
covers a class — not a static call.

### Method-name strings anywhere

There is no `shouldReceive('find')` form to fall back to. That is the whole
point of the library, and it is why a rename now breaks compilation rather than
matching.

### Localised failure messages

Understudy's messages are English only. They are also
[structured data](https://rasuvaeff.github.io/understudy/guide/failure-messages#reading-a-failure-as-data) if you are
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

---


# Migrating from PHPUnit

For suites using PHPUnit's own `createMock()` / `createStub()`. Verified
against PHPUnit 12.5.33; the mapping holds for 10 through 13.

> **Also rendered outside the site**
>
> This guide and [Migrating from Mockery](#migrating-from-mockery) are the
> same document as `MIGRATION.md` in the repository.

## The mapping

| PHPUnit | Understudy |
|---|---|
| `createMock(BookRepository::class)` / `createStub(…)` | `Understudy::for(…)` |
| `->expects($this->once())->method('find')` | `expect(fn () => $repo->find(…))` |
| `$this->once()` / `never()` / `exactly(n)` | `times(1)` / `times(0)` / `times(n)` |
| `$this->any()` | no `expect()` at all — use `when()` |
| `$this->atLeast(n)` | `times(n, null)` |
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
| `getMockForAbstractClass()` / `onlyMethods()` | a class double plus [`delegate()`](https://rasuvaeff.github.io/understudy/guide/forwarding) |
| automatic reset between tests | the [PHPUnit adapter](https://rasuvaeff.github.io/understudy/adapters/phpunit) trait |
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
non-overlapping matcher. See [Stubbing](https://rasuvaeff.github.io/understudy/guide/stubbing/index#which-stub-wins).

Where the mapping is genuinely tabular, one `answers()` with a `match` is
closer to the original.

### `expects()` is both count and behaviour; here they can be separate

```php
// PHPUnit
$repo->expects($this->once())->method('find')->with(7)->willReturn($book);

// Understudy — one registration, both things
expect(fn () => $repo->find(7))->returns($book);
```

An expectation needs no `returns()` at all: the [mode](https://rasuvaeff.github.io/understudy/guide/modes)'s default
supplies the value. That is why `$this->any()` translates to *no* `expect()` —
a call you do not count is a [stub](https://rasuvaeff.github.io/understudy/guide/stubbing/index), and writing it as
an expectation with an unbounded range says something the test does not mean.

### Partial doubles

`getMockForAbstractClass()` and `onlyMethods()` produce an object where the
un-named methods run for real. The understudy shape is a class double in
[forwarding](https://rasuvaeff.github.io/understudy/guide/forwarding) mode:

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
[`verify()`](https://rasuvaeff.github.io/understudy/guide/expectations/verify).

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
[`verifyAll(strictStubs: true)`](https://rasuvaeff.github.io/understudy/guide/expectations/strict-stubs), set once on
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
| `->shouldHaveBeenCalled()` | [`verify(…)`](https://rasuvaeff.github.io/understudy/guide/expectations/verify) |
| `$prophet->checkPredictions()` | `Understudy::verifyAll()`, or the adapter |

The structural difference: Prophecy keeps a separate object from the
revealed double. Understudy has one object, and the specification is a call
made on it — so there is no reveal step and no pair of variables to keep
straight.
