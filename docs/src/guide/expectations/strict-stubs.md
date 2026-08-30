---
title: Strict stubs
description: "verifyAll(strictStubs: true) — failing a test on a stub nobody used, and how each runner adapter turns it on."
---

# Strict stubs

A stub is [permission](/guide/intro/concepts#stub-and-expectation-are-different-claims),
so an unused one does not fail a test by default. Strict stubs reverse that:

```php
Understudy::verifyAll(strictStubs: true);
```

A stub configured but never called then fails its test too — the Mockito
reading of "why did you configure it, then?".

## Why it is off by default

Both readings are defensible, and understudy takes the position that stubbing
describes a collaborator while expecting asserts on it. A test that stubs three
methods of a repository and exercises a path through two of them is describing
the repository, and failing it teaches nothing.

The reading flips once a suite grows: an unused stub is then more often a stub
that *stopped* being used — the code moved, and the test kept a description of
a call that no longer happens. That is a real signal, and it is why this is
worth turning on project-wide rather than per test.

## Turning it on

::: code-group

```php [Testo]
new UnderstudyPlugin(strictStubs: true)
```

```php [PHPUnit or Pest]
abstract class ProjectTestCase extends TestCase
{
    use UnderstudyPHPUnitIntegration;

    protected function understudyStrictStubs(): bool
    {
        return true;
    }
}
```

```php [No adapter]
Understudy::verifyAll(strictStubs: true);
```

:::

## Strictness per double is a different thing

Do not confuse the two:

| | Scope | Fails on |
|---|---|---|
| `verifyAll(strictStubs: true)` | the whole verification | a stub that was **never used** |
| [`Understudy::strict($double)`](/guide/modes) | one double | a call **nothing configured** accepted |

The first is about registrations that went unused. The second is a
[mode](/guide/modes), about calls that arrived unconfigured — and it fails at
the call rather than at verification. They are independent: per-double
strictness is available from the core regardless of the adapter setting.

## The failure

See the [cookbook case](/cookbook/strict-stubs) for the message and for why the
unused stub was usually the bug.
