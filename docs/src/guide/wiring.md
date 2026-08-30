---
title: Wiring a subject
description: "Understudy::wire() — building the subject with a double per constructor dependency, and the parameter shapes it refuses."
---

# Wiring a subject

```php
['sut' => $service, 'doubles' => $d] = Understudy::wire(CatalogService::class);

/** @var Repository $repository */
$repository = $d['repository'];
when(fn () => $repository->find(1))->returns($book);

Assert::same($service->lookup(1), $book);
```

`wire()` reads the **constructor** and nothing else: no container, no property
injection, no setters. A unit test cares about the collaborators the class
itself asks for.

## What each parameter gets

| Constructor parameter | What it gets |
|---|---|
| a class or interface | a double, returned in `doubles` under the parameter name |
| a nullable object | a double — `null` is something the test can ask for explicitly |
| an intersection | one double of both contracts |
| a union of several object types | refused: picking one would be a guess |
| an object that cannot be doubled, with a default | its own default, applied by PHP |
| a scalar with a default | the declared default, and no double |
| a scalar without one | refused, naming the override to pass |
| a variadic tail | left empty; inventing entries would invent collaborators |
| a by-reference parameter | refused — overrides are values, and passing one would promise a reference semantics `wire()` does not have |

Every refusal happens **before** the constructor runs, so a wrong type is
reported by `wire()` rather than as a `TypeError` from inside the subject.

## Overrides

```php
Understudy::wire(CatalogService::class, overrides: ['clock' => $realClock]);
```

`overrides` replaces one dependency with a real instance or a double you built
yourself. Those are yours already, so they do **not** appear in `doubles`.

## Filling a variadic tail

```php
['sut' => $service] = Understudy::wire(TaggedService::class, ['tags' => ['a', 'b']]);
```

A tail takes a list, and every element is checked against the declared type
before the constructor runs. Anything that is not a list — a bare value, a
string-keyed array — is refused by name, as is an element of the wrong type.

::: warning One consequence
Filling a tail means the parameters before it are passed **positionally**, so
an omitted optional one has its declared default materialized. That is the one
place `wire()` evaluates a default rather than letting PHP apply it.
:::
