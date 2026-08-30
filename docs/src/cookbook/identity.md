---
title: Two objects that look alike
description: "The subject rebuilt the value instead of passing the one it was handed — and the alias table is what says so."
---

# Two objects that look alike

An object argument is matched by **identity**. Two instances never match, however
equally they read. So when a subject rebuilds a value rather than passing the
one it was handed, the expectation misses — and the interesting question is
which of the two reasons it was.

## The test

```php
$repository = Understudy::for(BookRepository::class);
$original = new Book('Dune');

// The subject saves the book it was handed once, and a rebuilt copy the
// second time. The copy is equal in every field and is not the same object.
$repository->save($original);
$repository->save(new Book('Dune'));

verify(fn () => $repository->save($original), times: 2);
```

## What it reports

<!-- case-study-output: identity -->
```text
Understudy `BookRepository` expected `save(Book#1 {title: 'Dune'})` to be called exactly 2 times, but it was called 1 time.

The following calls to `save` were made during this test:
    save(Book#1 {title: 'Dune'})
    save(*Book#2 {title: 'Dune'}*)
```

## Reading it

Both lines print `{title: 'Dune'}`. Without the aliases the log would say
"you expected this, and here are two calls that look exactly like it", which
is the least useful thing a failure can say.

`#1` and `#2` are numbered **within this message**, in order of first
appearance. The same instance keeps one number wherever it appears — so line
one says "this is the object you named" and line two says "this is a rebuilt
copy". The asterisks mark the argument that differed.

They are not object ids. An id is reused after a collection, and the same
failing test would print different numbers on the next run.

## The fix

Whichever the code meant:

- the subject should pass the instance through rather than rebuild it; or
- the identity was never the point, and the specification should say so:

```php
verify(fn () => $repository->save(Arg::which('title', 'Dune')), times: 2);
```

`Arg::which()` matches on what the object answers rather than on which object
it is. See [Argument matchers](/guide/stubbing/matchers).
