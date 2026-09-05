---
title: Failure messages
description: "The alias table, the asterisk marks, and the rules the renderer follows — including the one about never calling a getter to print a message."
---

# Failure messages

```text
Understudy `BookRepository` expected `tag('alpha', 2)` to be called exactly 1 time,
but it was never called.

The following calls to `tag` were made during this test:
    tag(*'beta'*, 2)
```

The asterisks mark the argument that differed — borrowed from
[NSubstitute](https://nsubstitute.github.io).
`Understudy::label($double, '…')` names a double when several of the same
contract are in play.

## Two instances that read alike

An object argument is matched by identity, so two instances never match however
equally they read — and the message has to be able to show which of the two
reasons it was:

```text
Understudy `BookRepository` expected `save(App\Book#1 {title: 'Dune'})` to be called
exactly 2 times, but it was called 1 time.

The following calls to `save` were made during this test:
    save(App\Book#1 {title: 'Dune'})
    save(*App\Book#2 {title: 'Dune'}*)
```

`#1` and `#2` are aliases numbered **within one message**, in order of first
appearance. The same instance keeps one number wherever it appears, so the log
line above says "this is the object you named" and the marked one says "this is
a rebuilt copy".

::: warning They are not object ids
An id is reused after a collection, and the same failing test would print
different numbers on different runs. The alias is scoped to the message so that
a message can be read, quoted and diffed.
:::

The [cookbook case](/cookbook/identity) has the runnable version.

## What the braces show

Public properties, up to five, at the same depth budget the rest of the message
uses.

**Nothing is called to render them.** An object that keeps its state behind
getters renders as its alias alone — running a getter to print a message would
run the code under test at the worst possible moment.

## A strict double refuses at the call

It says what it compared the call against:

```text
Understudy `BookRepository` is strict and received an unexpected call to `tag()`.

The call was:
    tag('beta', 1)

Nothing configured for `tag` accepted it:
    tag(*string(matches: /^a/)*, *2*)

Configure it first: when(fn () => $double->tag(...))->returns(...)
```

Here the marks are read from the **expectation's** side: each one is an
argument that rejected this call, including a position the call never carried.

Everything configured for that method is listed — the dispatcher's own order,
up to five, then a count — because a stub that could never have matched is
often exactly the one the test meant to write. When nothing at all is
configured for the method, there is nothing to compare against and the message
stays the single line naming it.

## What is redacted

A parameter the contract marks `#[\SensitiveParameter]` is rendered as its type
and nothing else — `login('user', string SensitiveParameter)` — in every message
and in `transcript()`, the way PHP redacts such a parameter in its own traces.
Everything else is printed as written; see [Security](/guide/security).

## Reading a failure as data

An adapter or a reporter should not parse these strings.
`VerificationFailed::failures()` returns the same information structured; see
`examples/structured-failures.php` in the repository.

The wording of a message is not part of the public contract — a patch release
may reword one. What is stable are the readonly fields of `VerificationFailure`
and every existing `FailureKind` case, which is why anything acting on a failure
reads those instead; a **new** kind may arrive in a minor release, so match on
the enum with a `default` arm rather than exhaustively. A test asserting on the
exact text of a message is asserting on prose.
