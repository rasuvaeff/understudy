---
title: The spy that counted the wrong calls
description: "An expectation counts only the calls matching its own arguments — the Mockery migration trap that leaves a green suite."
---

# The spy that counted the wrong calls

The trap people carry over from Mockery most often, and the one that leaves a
**green** suite rather than a confusing failure.

## The test

```php
$repository = Understudy::for(BookRepository::class);
$expected = new Book('Dune');

expect(fn () => $repository->save($expected));

// The subject also saves something the test never asked about.
$repository->save($expected);
$repository->save(new Book('Neuromancer'));

Understudy::verifyAll();
```

This passes.

## What it reports

Adding one line — `Understudy::nothingElse($repository)` — turns it into:

<!-- case-study-output: spy-counter -->
```text
verifyAll() alone: passed

Understudy `BookRepository` received 1 call(s) nothing accounted for:
    save(Book#1 {title: 'Neuromancer'})
```

## Why the first check passed

`expect()` counts the calls matching **its own** arguments. It claimed
`save($expected)`, it got exactly one, and it is satisfied. It never said
anything about the rest of the traffic — nor should it, because a test that
stubs three collaborators and exercises two of them is not asserting on the
third.

A Mockery spy that counted every call to `save` did make that wider claim. The
migration has to say it out loud.

## The fix

```php
expect(fn () => $repository->save($expected));
Understudy::nothingElse($repository);        // ← the wider claim
```

Or both in one:

```php
Understudy::allVerified($repository);
```

`nothingElse()` takes any number of doubles, so one line closes out a whole
test — and it names **every** offender rather than stopping at the first.

::: tip What counts as accounted for
An `expect()` that matched, or a **successful** `verify()`. A `when()` stub
accounts for nothing: it is permission, not a description of what happened.
See [Has everything been described?](/guide/expectations/nothing-else).
:::
