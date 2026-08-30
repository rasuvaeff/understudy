---
title: Using Pest
description: "The expect() name collision Pest introduces, the two ways to resolve it, and wiring the adapter into Pest.php."
---

# Using Pest

Pest works through the [PHPUnit adapter](/adapters/phpunit) — a Pest test is a
`TestCase` underneath, so the trait applies unchanged.

One thing needs a decision, and it is the same decision in every Pest project.

## The `expect()` collision

Pest already owns a global `expect()` function. Importing understudy's setup
verb collides with it.

Two ways out, both fine:

```php
// 1. Import understudy's under another name.
use function Rasuvaeff\Understudy\expect as expectCall;

expectCall(fn () => $books->find(7));
```

```php
// 2. Use the collision-free static form everywhere.
Understudy::expect(fn () => $books->find(7));
```

`when()` and `verify()` are globally free and need no alias.

::: tip Pick one and keep it
Mixing the two forms in one suite reads as if they did different things. The
static form is the safer default in a Pest codebase: it never collides, and a
reader coming from Pest's own `expect()` is not left wondering which one a bare
call is.
:::

## Wiring the adapter

The trait goes on the base test case Pest uses:

```php
// tests/Pest.php
uses(ProjectTestCase::class)->in('Feature', 'Unit');
```

```php
abstract class ProjectTestCase extends TestCase
{
    use UnderstudyPHPUnitIntegration;
}
```

Verification and reset then run after every Pest test, the same as under
PHPUnit proper.

To turn on [strict stubs](/guide/expectations/strict-stubs) for the whole
project, override `understudyStrictStubs()` on that same base class.

## If you override `assertPostConditions()`

Compose explicitly — see
[the PHPUnit adapter page](/adapters/phpunit#overriding-assertpostconditions).
PHP resolves a method-name conflict between a class and a trait silently in
favour of the class, so the trait's verification would stop running with no
error at all.
