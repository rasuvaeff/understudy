---
title: Has everything been described?
description: "nothingElse() and allVerified() — closing the gap a spy leaves open, across any number of doubles."
---

# Has everything been described?

```php
Understudy::nothingElse($repository);                    // every call was accounted for
Understudy::nothingElse($repository, $clock, $mailer);   // across several doubles
Understudy::allVerified($repository);                    // expectations met AND nothing else
```

## What counts as accounted for

| | Accounts for a call |
|---|---|
| An `expect()` that matched it | yes |
| A **successful** `verify()` that claimed it | yes |
| A failed `verify()` | no |
| A `when()` stub | **no** |

A stub accounts for nothing because it is permission, not a description of what
happened. This is the rule that makes `nothingElse()` worth having: a test can
stub freely and still assert that the subject made no call it did not mean to.

## Why one line, many doubles

`nothingElse()` takes any number of doubles, so one line closes out the whole
test. A failure names **every** offender rather than stopping at the first —
the second unexpected call is usually as interesting as the first, and finding
it one run at a time is the slow way.

## The trap this closes

A spy-style test that counts calls will happily pass while the subject also
makes a call with entirely different arguments:

```php
expect(fn () => $repository->save($expected));
// subject also calls $repository->save($somethingElse) — still green
```

`expect()` claims the calls it matched. It says nothing about the rest.
Add `nothingElse()`, or use `allVerified()`, which is both checks in one:

```php
Understudy::allVerified($repository);
```

`allVerified()` checks [ordered](/guide/expectations/ordering) expectations
too.

See the [cookbook case](/cookbook/spy-counter) for the full worked example —
it is the trap people carry over from Mockery most often.
