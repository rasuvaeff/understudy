---
title: Retiring a double
description: "forget() for a double the test replaced, reset() for everything, and why both are one-way."
---

# Retiring a double

```php
Understudy::forget($replaced);
```

For the double a test built and then replaced. `$this->generator =
$this->fixedGenerator('other')` leaves the first one behind, still holding its
stubs.

Under [`verifyAll(strictStubs: true)`](/guide/expectations/strict-stubs) that
stub is a failure about a double the test no longer uses. `forget()` retires
it, so verification, accounting and reset stop seeing it.

## Afterward

Calling anything on the object — or asking about its calls — fails with
`ForgottenDouble`, which names `forget()` rather than sending you looking for a
`reset()` you never wrote.

One-way, like every other form of forgetting here.

## `forget()` against `reset()`

| | Scope |
|---|---|
| `Understudy::forget($double)` | one double, retired from the current context |
| `Understudy::reset()` | every double, registration and transcript the test put in place |

```php
Understudy::reset();
Understudy::idle();   // true when the current context holds no doubles
```

The [Testo](/adapters/testo) and [PHPUnit](/adapters/phpunit) adapters call
`reset()` for you after every test. Without one, call it in your own teardown.

`idle()` is the check a base class can make to catch a suite where something
leaks between tests.
