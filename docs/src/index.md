---
layout: home
title: understudy
description: "Test doubles for PHP 8.3+ specified by calling the method, not by naming it in a string: when(fn () => $repo->find(1))->returns($book)."
hero:
  name: understudy
  text: Specify the call by making it.
  tagline: No method names in strings. One engine, two runner adapters and two static analysers.
  image:
    src: /logo-mark.svg
    alt: understudy logo
  actions:
    - theme: brand
      text: What is understudy?
      link: /guide/intro/what-is-understudy
    - theme: alt
      text: Getting started
      link: /guide/intro/getting-started
    - theme: alt
      text: View on GitHub
      link: https://github.com/rasuvaeff/understudy
features:
  - title: The call is the specification
    details: "when(fn () => $repo->find(1))->returns($book) — the method is called, so a rename, a wrong argument type or a dropped parameter is a type error before the test runs."
    link: /guide/stubbing/index
  - title: Matchers that keep their types
    details: Arg::any(), Arg::same(), Arg::satisfies() and Arg::captor() read through the contract, so a matcher in the wrong position is caught by Psalm and PHPStan.
    link: /guide/stubbing/matchers
  - title: Failure messages that point
    details: The asterisks mark the argument that differed, and object aliases say which of two look-alike instances the call carried.
    link: /guide/failure-messages
  - title: Order when you need it
    details: ordered(), verifySequence() and expectSequence() — the last one fails at the call that broke the protocol, not in teardown.
    link: /guide/expectations/ordering
  - title: Real objects underneath
    details: delegate() runs the real object for everything not stubbed, and records it. wire() builds the subject with its doubles in place.
    link: /guide/forwarding
  - title: Bring your own runner
    details: A Testo interceptor, a PHPUnit trait, Pest, or no framework at all — verification is the same engine underneath.
    link: /adapters/testo
---

<div class="vp-doc" style="max-width: 960px; margin: 3rem auto 0; padding: 0 24px;">

## Five packages, one engine

- <img src="/logo-mark.svg" width="20" height="20" alt="" style="display: inline-block; vertical-align: middle; border-radius: 4px; margin-right: 4px;" /> **[`understudy`](https://github.com/rasuvaeff/understudy)** — the engine: doubles, stubbing, expectations, verification, transcripts. Depends on nothing but PHP.
- <img src="/adapters/testo/logo-mark.svg" width="20" height="20" alt="" style="display: inline-block; vertical-align: middle; border-radius: 4px; margin-right: 4px;" /> **[`understudy-testo`](/adapters/testo)** — verification and reset wired into [Testo](https://php-testo.github.io/)'s lifecycle, with Fiber isolation.
- <img src="/adapters/phpunit/logo-mark.svg" width="20" height="20" alt="" style="display: inline-block; vertical-align: middle; border-radius: 4px; margin-right: 4px;" /> **[`understudy-phpunit`](/adapters/phpunit)** — the same, through a trait; works under Pest.
- **[`understudy-psalm`](/adapters/psalm)** — a Psalm plugin that types the specification closure and reports misuse.
- **[`understudy-phpstan`](/adapters/phpstan)** — the same checks as PHPStan rules, under the `understudy.*` identifiers.

Install the engine plus exactly the adapter your suite already uses. The static
analysers are independent of both: either one can be added on its own.

## The message tells you which one it was

An object argument is matched by identity, so two instances never match however
equally they read. The message has to be able to say which of the two reasons a
call failed — and it does:

<div class="terminal-sample">

```text
Understudy `BookRepository` expected `save(App\Book#1 {title: 'Dune'})` to be called
exactly 2 times, but it was called 1 time.

The following calls to `save` were made during this test:
    save(App\Book#1 {title: 'Dune'})
    save(*App\Book#2 {title: 'Dune'}*)
```

</div>

`#1` and `#2` are aliases numbered within one message, in order of first
appearance — not object ids, which are reused after a collection and would print
differently on the next run. The asterisks mark what differed.

See the [Cookbook](/cookbook/identity) for this and four other real incidents,
each with a script you can run.

</div>

<style>
/* The sample is a fenced block inside a plain <div>, not a raw <pre>: a blank
   line inside a raw HTML block ends that block in markdown-it, and the Vue SFC
   compiler then sees an unbalanced <div> and fails the build. Blank lines
   around the fence keep the div a well-formed HTML block on its own. */
/* Light mode only, for the same reason as custom.css: in dark mode VitePress's
   code background is already dark and correct, and forcing the brand colour
   over it would fight the theme rather than help it. */
:root:not(.dark) .terminal-sample div[class*='language-'] {
  background: #1e1b2e;
  border: none;
}
:root:not(.dark) .terminal-sample div[class*='language-'] code {
  color: #f2f1fa;
}
.terminal-sample div[class*='language-'] pre {
  padding: 1rem 1.2rem;
}
.terminal-sample div[class*='language-'] code {
  font-size: 0.85rem;
  line-height: 1.6;
}
.terminal-sample div[class*='language-'] span.lang,
.terminal-sample div[class*='language-'] button.copy {
  display: none;
}
</style>
