---
title: Property hooks
description: "Doubling a contract that declares properties on PHP 8.4+, and why a property read is not a call."
---

# Property hooks

::: tip PHP 8.4+
Property hooks are a PHP 8.4 language feature. On 8.3 a contract cannot declare
one, so there is nothing to double.
:::

A contract declaring a property is doublable:

```php
interface Customer
{
    public string $name { get; }
    public Money $balance { get; set; }
}

$customer = Understudy::for(Customer::class);
$customer->name;      // the mode's type-safe default: ''
$customer->balance;   // a depth-1 double of Money, or your defaults() registration
```

## Why a `__get`-based library cannot do this

`__get` fires only for an **inaccessible** property — precisely not the case
once the contract declares it. A library that intercepts through magic methods
has nothing to intercept here.

Understudy generates the class source, so it declares the property and puts the
dispatcher inside the hook. Exactly the declared hooks are rendered: a get-only
property refuses a write with PHP's own error, not with a library message.

## What a read answers

In order:

1. On a [forwarding](/guide/forwarding) double, the real instance's value.
2. Whatever the code under test wrote earlier — a `{ get; set; }` property
   behaves like a plain one.
3. Otherwise the same default table a method return goes through:
   [`Understudy::defaults()`](/guide/defaults) registrations included, and the
   depth-1 nested double.

## A property read is not a call

This is the rule to carry away:

| | Property read | Method call |
|---|---|---|
| Recorded in the transcript | no | yes |
| Specifiable with `when()` / `expect()` | no | yes |
| Judged by [strict mode](/guide/modes) | no | yes |

It is the same standing a plain public property already has on a class double.
A `clone` of the double does not carry written property values over either — a
copy is a double of its own, there as everywhere.

## Two shapes still refused

Both with the reason, rather than silently:

- a `readonly` class target whose contract carries an abstract hook — a
  readonly class may only be extended by a readonly class, and a hooked
  property cannot be readonly;
- a by-reference `&get` hook.

## Parameter defaults are reproduced, not approximated

Worth knowing because it decides what a generated double can carry at all:

- a class constant is rendered through its declaring class;
- an enum case as itself;
- an object default from its own source expression — `new Stamp(7)` and
  `[new Stamp(7)]` alike — which is never evaluated while the double is
  generated.

A default whose source names `self`, `static` or `parent` refuses the target:
those resolve against the generated class and would answer something the
contract never promised.
