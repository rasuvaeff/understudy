---
title: PHPStan extension
description: "rasuvaeff/understudy-phpstan — matchers typed as never, returns() checked against the method, wire() shaped from the constructor, and five rule identifiers."
---

# PHPStan extension

`rasuvaeff/understudy-phpstan` is four things PHPStan cannot see on its own.

```bash
composer require --dev rasuvaeff/understudy-phpstan
```

With [`phpstan/extension-installer`](https://github.com/phpstan/extension-installer)
that is all. Without it, include the extension yourself:

```neon
includes:
    - vendor/rasuvaeff/understudy-phpstan/extension.neon
```

The shared reasoning is on [Static analysis](/guide/static-analysis); this page
is the PHPStan specifics.

## 1. A matcher fits whatever the contract declares

`Arg::int()` is declared `mixed`. At level 9 and above PHPStan reports
`Parameter #1 $id … expects int, mixed given` — correct about the type, wrong
about the code.

The extension types every matcher as `never`, the bottom type, which every
parameter accepts. **Nothing is suppressed:**

```php
when(fn () => $repository->rename(Arg::any(), 'not an int'));
//                                            ^^^^^^^^^^^^ still reported
when(fn () => $repository->missing(Arg::any()));
//                        ^^^^^^^ still reported
```

Below level 9 there is nothing to fix here — PHPStan does not check `mixed`
against a declared parameter — and the rest of the extension works at every
level.

The two 0.4 idioms are covered the same way: `Arg::rest()` makes the
`arguments.count` report go quiet wherever a call's last written argument is
`Arg::rest()`, and `$captor->capture()` is typed `never` like the `Arg::`
factories (the receiver's type is what decides — a foreign `capture()` is left
alone). `Arg::captor()` itself is a factory, not a matcher, and legitimately
lives outside the closure.

## 2. `returns()` is checked against the method being specified

The core declares `when(): WhenBuilder<mixed>`, and it has no choice: which
method is being specified is known only from the closure. The extension fills
the template parameter in, and PHPStan does the rest:

```php
when(fn () => $gate->open(1))->returns('yes');
// Parameter #1 ...$values of method WhenBuilder<bool>::returns() expects bool, string given.
```

## 3. `wire()` has the shape of the class it wired

```php
$wired = Understudy::wire(Checkout::class);

$wired['doubles']['repository'];  // Offset 'repository' does not exist on
                                  // array{books: BookRepository, clock: Clock}.
$wired['doubles']['clock']->tick();  // Call to an undefined method Clock::tick().
```

## 4. Specifications that cannot work are reported

Each has a run-time counterpart — the engine throws, or the expectation never
matches. Reporting them statically buys the one thing run time cannot: a
specification that can never match is exactly the mistake a green suite hides.

| Identifier | Reported when |
|---|---|
| `understudy.closure` | the closure specifies nothing, makes more than one call, or calls a static method a double cannot intercept |
| `understudy.cardinality` | `times(5, 2)`, a negative bound, `verify(…, never: true, times: 3)`, `times` beside a `minimum` |
| `understudy.matcher` | a matcher whose kind the parameter can never accept: `Arg::int()` where a `string` is declared |
| `understudy.returns` | `returns()` on a method declared `void`, where no value is ever observed |
| `understudy.matcherLeak` | a matcher written outside a specification and outside any closure, where it reaches the code as a value; one hoisted into a variable, stored on a property or written in a closure handed over later is not one |

To silence one, use its identifier:

```neon
parameters:
    ignoreErrors:
        - identifier: understudy.matcherLeak
```

## Why `understudy.matcherLeak` exists

Typing every matcher as `never` is what lets one stand in for a typed
parameter, and it does so everywhere — including in a real call:

```php
$repository->find(Arg::int());  // not a specification: the matcher is the argument
```

Without a rule for it the extension would be **weaker** than no extension for
that mistake, because PHPStan would otherwise have reported the argument
itself.

Saying it directly is also better than the type error it replaces: at run time
the matcher reaches the code as a sentinel object, and the failure it
eventually causes names neither the matcher nor the line.

## Silent when unsure

A refined parameter type — `non-empty-string`, an int range — answers "maybe"
to its plain kind, and a matcher can produce a value that fits it, so nothing
is reported. A false accusation costs more than a missed one here.
