---
title: Concepts
description: "Double, contract, specification, stub, expectation, verification, transcript, mode and context — the words the rest of this documentation uses precisely."
---

# Concepts

Nine words carry the rest of these pages. They are used precisely, and the
distinctions between them are load-bearing rather than stylistic.

| Word | Means |
|---|---|
| **Contract** | The interface or class a double stands in for. `Understudy::for(BookRepository::class)` doubles the `BookRepository` contract. |
| **Double** | The generated object. It has the contract's type and no members of its own — everything the library offers lives on `Understudy` or in free functions. |
| **Specification closure** | The closure passed to `when()`, `expect()`, `verify()` or `calls()`. It performs a real call on a double, and the library reads what happened inside it. |
| **Stub** | A registration that says what a call answers. Permission, not a claim: a stub nobody used is not a failure unless you ask for [strict stubs](/guide/expectations/strict-stubs). |
| **Expectation** | A registration that says a call must happen, and how often. A claim, checked at verification. |
| **Verification** | Checking the claims. `Understudy::verifyAll()` at the end of a test, or `verify()` for a single call after the fact. |
| **Transcript** | The recorded log of every call a double received, with its outcome. Always on — verification never has to be set up in advance. |
| **Mode** | What an *unmatched* call does: answer a type-safe default (loose), fail immediately (strict), or run the real object (forwarding). |
| **Context** | What owns a set of doubles and their registrations. One per fiber, nested by `Understudy::scope()`, cleared by `reset()`. |

## Stub and expectation are different claims

This is the distinction that matters most, and the one every mocking library
words differently.

```php
when(fn () => $repo->find(7))->returns($book);   // if it is called, this is the answer
expect(fn () => $repo->find(7));                 // it must be called, exactly once
```

A stub is permission. It does not fail a test by going unused, because a test
that stubs three collaborator methods and exercises a path using two of them is
describing the collaborator, not asserting on it.

An expectation is a claim. It fails if the count does not hold.

They are separate concerns, which is why an expectation needs no `returns()` —
the mode's default supplies the value, and a matched expectation satisfies even
a strict double, because the call was expected. It is also why the two do not
stack for one call: whichever were declared later would take the dispatch and
silently void the other, so the second registration is refused. See
[Getting started](/guide/intro/getting-started#two-rules-that-catch-everyone-once).

## The specification closure is a real call

`fn () => $repo->find(7)` is not a description that the library parses. The
call runs against the double, which records the method and the arguments, and
the registration is built from that recording.

Two consequences follow, and both come up in practice:

- **The arguments are evaluated.** A matcher such as `Arg::any()` is a value
  passed in the ordinary way, which is why matchers are typed to read through
  the contract and why a leaked matcher — one built but never passed to a
  specification — is reported rather than silently ignored.
- **Only one call belongs in one closure.** The closure specifies the call it
  makes. A closure that makes two says nothing coherent about either.

## Verification is not the same as recording

Every double records every call, always. That log is the transcript, and it is
what makes `verify()`, `Understudy::calls()` and
[`nothingElse()`](/guide/expectations/nothing-else) work without any prior
setup: the evidence was collected whether or not anybody planned to look at it.

Verification is the act of checking a claim against that log. Recording is
unconditional; verification is what you asked for.

Retention has a cost, and it has one sharp edge worth knowing before you meet
it: what a double returned stays referenced until the context is cleared, which
with the runner adapters happens *after* your teardown. For a returned value
that owns an OS resource — a stream, a lock, a connection — that resource is
still held while teardown runs. [`lean()`](/guide/lifecycle/retention) and
`scope()` are the two ways out.

## Contexts and fibers

A context owns doubles, their registrations and their transcripts. There is one
per fiber rather than one static bag per process, which is why a test body run
inside a fiber cannot leave an unmet expectation behind in some other context's
bookkeeping.

Configuration and verification must run in the context that owns the double.
Ordinary calls may be made from another fiber and are still recorded in the
owner's log. [Fiber isolation](/guide/lifecycle/fibers) covers the details.

## Where these words appear next

- [Creating a double](/guide/doubles/creating) — contracts and what may be
  doubled.
- [Stubbing](/guide/stubbing/index) and
  [Expectations](/guide/expectations/index) — the two registration kinds in
  full.
- [Modes](/guide/modes) — what an unmatched call does.
- [Phases, scopes and transcripts](/guide/lifecycle/index) — contexts in
  practice.
