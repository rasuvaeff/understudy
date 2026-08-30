---
title: Chaining behaviour
description: "then() and multi-value returns() — answering a sequence of calls differently, and what happens when the chain runs out."
---

# Chaining behaviour

One call, several answers, in order:

```php
when(fn () => $breaker->call($operation))
    ->returns('ok')
    ->then()->throws(new ConnectionLost());
```

One link per call. When the chain runs out, the last link keeps answering.

## Two forms

```php
// Values only — the compact form.
when(fn () => $repository->mode())->returns('fast', 'slow');

// Mixed outcomes — the chained form.
when(fn () => $client->send($request))
    ->throws(new Timeout())
    ->then()->throws(new Timeout())
    ->then()->returns($response);
```

`returns($a, $b, …)` is the shorthand for a chain of values. Reach for `then()`
when the links differ in kind — a throw followed by a value, or an
[`answers()`](/guide/stubbing/index) callback among literals.

This is the shape a retry or circuit-breaker test wants: fail, fail, succeed,
and then keep succeeding without a fourth link having to be written.

## The last link repeats

Deliberately, and it is the behaviour to design around: a chain describes the
interesting prefix of a sequence, not its full length. A test that cares how
many calls happen should say so with an
[expectation](/guide/expectations/index) — the chain answers, the expectation
counts.
