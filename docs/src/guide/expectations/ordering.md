---
title: Call order
description: "ordered(), verifySequence() and expectSequence() — and which of the three fails at the call that broke the order."
---

# Call order

Three tools, and the difference between them is **when** the failure happens.

| Tool | Constrains | Fails |
|---|---|---|
| `expect(…)->ordered()` | the ordered expectations relative to each other; unrelated calls may happen in between | in teardown |
| `Understudy::verifySequence(…)` | the exact protocol, across doubles | in teardown |
| `Understudy::expectSequence(…)` | the exact protocol, armed before the run | **at the offending call** |

## Relative order

```php
expect(fn () => $repository->begin())->ordered();
expect(fn () => $repository->commit())->ordered();
```

Unrelated calls may happen in between. `ordered()` is also the tool when a step
repeats — a sequence expects each step exactly once.

## The exact protocol, checked afterwards

```php
Understudy::verifySequence(
    fn () => $repository->begin(),
    fn () => $repository->save($book),
    fn () => $repository->commit(),
);
```

It compares the double identity as well as the method and arguments, so several
doubles implementing the same contract stay distinguishable.

Both of the above are retrospective: the exception is raised in teardown, and
the stack trace points at `verifyAll()` rather than at the call that went out
of turn.

## The exact protocol, checked at the call

`expectSequence()` arms the protocol **before** the subject runs, so the
refusal happens inside the offending call and the subject's own frame is on top
of the stack:

```php
Understudy::expectSequence(
    fn () => $repository->begin(),
    fn () => $repository->save($book),
    fn () => $repository->commit(),
);

$service->handle($command);   // fails here, on the call that broke it
```

```text
Understudy `BookRepository` received a protocol call out of turn: step 2 of 3 was expected to be `save(App\Book#1 {title: 'Dune'})`.

The call was:
    commit()

The protocol is:
    1. begin()
    2. save(App\Book#1 {title: 'Dune'})   <- due here
    3. commit()
```

### The rules

| | |
|---|---|
| **Scope** | the doubles the protocol names. A double it never names is invisible to it |
| **On a named double** | the call is the step due, or something the test configured — anything else is refused |
| **Each step** | due exactly once, in order. `ordered()` is the tool for a relative order that tolerates repeats |
| **Unfinished** | arming is also a claim: `verifyAll()` reports the steps the subject never reached |
| **One at a time** | arming a second protocol while one is still running is refused; a finished one may be replaced |
| **`checkpoint()`** | verifies the protocol with everything else, then drops it — it belongs to the phase that declared it |

### The price of the second row

A query the subject makes between two steps — `$repository->find(7)` between
`begin` and `save` — has to be stubbed with `when()`.

Without it the protocol cannot tell "not part of this" from "you got the order
wrong", and guessing would put the failure back in teardown, which is exactly
what arming exists to avoid. The [cookbook case](/cookbook/protocol) walks
through the refusal message.

### A broad `catch` can swallow the refusal

That is why arming is a claim as well as a guard: if the subject catches the
exception, the test still fails — in teardown, with the step it stopped at.
