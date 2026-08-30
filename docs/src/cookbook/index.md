---
title: Cookbook
description: "Real incidents, each with a script whose output this site checks against the page."
---

# Cookbook

Five cases, each one a mistake that produced a green suite or an unhelpful
failure, and each with a runnable script in
[`examples/case-studies/`](https://github.com/rasuvaeff/understudy/tree/master/examples/case-studies).

The output quoted on every page is **diffed against what its script prints**
(`make docs-cookbook`, and a step of its own in CI). A reflowed message or a
renamed exception fails the build rather than leaving a plausible-looking
fiction on the page.

| Case | What went wrong |
|---|---|
| [Two objects that look alike](/cookbook/identity) | the subject rebuilt the value instead of passing the one it was handed, and the two read identically |
| [The spy that counted the wrong calls](/cookbook/spy-counter) | an expectation counted its own call and said nothing about a second one with different arguments |
| [A query between two ordered steps](/cookbook/protocol) | a read between two protocol steps was refused, because a protocol cannot guess |
| [The stub nobody used](/cookbook/strict-stubs) | a stub described a call the subject had stopped making |
| [A double that held a file handle](/cookbook/retention) | the call log retained a real stream past teardown, and only Windows noticed |

## Reading these

Each page has the same three parts: what the test looked like, what it
reported, and what the fix is. The middle part is the one that matters — a
failure message that names the thing is what turns a two-hour session into a
two-minute one, and several of these messages exist because the failure they
replaced did not.

For the API one concept at a time, see [Examples](/guide/examples) instead.
