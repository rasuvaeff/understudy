---
title: Creating a double
description: "Doubling an interface, combining several, doubling a class, and the targets understudy refuses on purpose."
---

# Creating a double

```php
use Rasuvaeff\Understudy\Understudy;

$repository = Understudy::for(BookRepository::class);
```

`for()` returns the contract's own type, so your IDE and your static analyser
treat `$repository` as a `BookRepository`. It carries no members of its own —
see [What is understudy](/guide/intro/what-is-understudy#no-members-on-the-double)
for why.

## Combining contracts

Several interfaces can be doubled at once:

```php
$double = Understudy::for(BookRepository::class, Paginated::class);
```

Understudy unifies compatible signatures across them:

| | |
|---|---|
| Parameter types | widened |
| Return types | the narrowest compatible declaration, or a synthesised interface intersection |
| Named arguments | follow the first (primary) interface |

Static contract methods exist on the generated class, because the interface has
to be implemented — but calling one raises `InvalidCallSpecification`. A static
call has no double instance to own its state.

::: warning A built-in interface still declares `mixed`
The additional contracts in these examples are your own interfaces on purpose.
Doubling one of PHP's own — `Countable`, `ArrayAccess`, `IteratorAggregate` —
generates the method with a `mixed` return rather than the interface's declared
one, and PHP answers with a deprecation notice:

```text
Deprecated: Return type of …::count(): mixed should either be compatible with
Countable::count(): int, or the #[\ReturnTypeWillChange] attribute should be
used to temporarily suppress the notice
```

The double works; the notice is noise today and a hard error on a future PHP.
A userland interface with the same method is unified correctly.
:::

## Doubling a class

A class can be the first target, with interfaces after it:

```php
$repository = Understudy::for(DoctrineBookRepository::class, Paginated::class);
```

What a class double does and does not do:

| | |
|---|---|
| The target's constructor | never runs — the double is built without it, so no side effect of construction reaches your test |
| Public and protected methods | overridden and dispatched; a protected one shows up in the transcript and under strict mode, but PHP's own visibility keeps it out of a specification closure |
| Private and static methods | untouched — the target keeps them, because there is no instance state to intercept |
| The destructor | replaced with an empty one, so nothing is torn down that was never built |
| Writable public properties | start at an empty value of their type; object-typed, hooked, `final`, `readonly` and `private(set)` ones are left uninitialized, and reading one raises PHP's own error |
| `clone` | produces a double of its own: same contracts, no expectations, no call log, owned by the context that cloned it |

A `readonly` target produces a `readonly` double, which PHP requires and which
costs nothing — the double declares no properties of its own.

## What is refused, and why

Some targets are refused before anything is generated, each with the reason and
what to do instead:

- a `final` class — see [Doubling a final class](/guide/doubles/final-classes)
- a class with a non-private `final` instance method
- an enum, a trait, an internal class, an anonymous class
- any class that is not the first target

The rule behind all of them is one rule. A double that cannot intercept every
method would run the target's real code against an object whose constructor
never ran — which is worse than not building the double at all.

## Next

- [Property hooks](/guide/doubles/property-hooks) — doubling a contract that
  declares properties, on PHP 8.4+.
- [Doubling a final class](/guide/doubles/final-classes) — `bypassFinals()` and
  its limits.
- [Stubbing](/guide/stubbing/index) — saying what the double answers.
