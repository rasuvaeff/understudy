<?php

declare(strict_types=1);

/**
 * What an unconfigured call answers, and how to say what it should answer.
 *
 *   docker run --rm -v "$PWD":/app -w /app composer:2 php examples/defaults-registry.php
 */

namespace Rasuvaeff\Understudy\Examples;

use Rasuvaeff\Understudy\Exception\NoDefaultValue;
use Rasuvaeff\Understudy\Understudy;

use function Rasuvaeff\Understudy\when;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_check.php';

interface Cache
{
    public function get(string $key): ?string;

    public function tagged(string $tag): Cache;
}

interface Session
{
    public function id(): string;
}

interface Registry
{
    public function cache(): Cache;

    public function session(): ?Session;
}

final class NullCache implements Cache
{
    public function get(string $key): ?string
    {
        return null;
    }

    public function tagged(string $tag): Cache
    {
        return $this;
    }
}

// --- A nested double, one level deep ---------------------------------------------
//
// A return type that can itself be doubled becomes one, so a collaborator graph
// stays usable without the test naming every node.

echo "nested doubles\n";

$registry = Understudy::for(Registry::class);
$cache = $registry->cache();

check($cache instanceof Cache, 'a doublable return type comes back as a double, not null');

// Each call builds its own: the engine is answering a return type, not managing
// a graph. A test that needs to hold on to one says so.
check($registry->cache() !== $cache, 'each call builds a fresh nested double, so do not configure the one you happened to take');

$pinned = Understudy::for(Cache::class);
when(fn() => $registry->cache())->returns($pinned);
check($registry->cache() === $pinned, 'a stub is how a test pins the collaborator it wants to configure');

check($cache->get('anything') === null, 'a nested double answers scalars from the same table as any other');

// Depth stops at one, and it stops by refusing rather than by documentation:
// `$a->b()->c()` would otherwise keep inventing collaborators the test never
// asked for and cannot see.
try {
    $cache->tagged('books');
    check(false, 'a second level of nesting was expected to be refused');
} catch (NoDefaultValue $refusal) {
    check(
        str_contains($refusal->getMessage(), 'tagged'),
        'a nested double refuses to produce another: ' . explode("\n", $refusal->getMessage())[0],
    );
}

Understudy::reset();

// --- Saying what the default should be --------------------------------------------

echo "\nUnderstudy::defaults()\n";

Understudy::defaults(Cache::class, static fn(): Cache => new NullCache());

$registry = Understudy::for(Registry::class);

check($registry->cache() instanceof NullCache, 'a registered factory answers instead of a nested double');

Understudy::reset();

// A nullable return is `null` until a factory says otherwise — saying what the
// type should be means it there too.

echo "\nnullable returns\n";

$registry = Understudy::for(Registry::class);
check($registry->session() === null, 'a nullable return with no registration is null');

Understudy::reset();

Understudy::defaults(Session::class, static fn(): Session => new class implements Session {
    public function id(): string
    {
        return 'session-1';
    }
});

$registry = Understudy::for(Registry::class);
check($registry->session()?->id() === 'session-1', 'a registration outranks null on a nullable return');

Understudy::reset();

// --- When no safe value exists ------------------------------------------------------
//
// The engine never invents an instance of a class it cannot double, and never
// hands back an unconstructed one. It says so, and names the way out.

echo "\nno safe default\n";

interface NeedsAnEnum
{
    public function suit(): Suit;
}

enum Suit
{
    case Hearts;
    case Spades;
}

$dealer = Understudy::for(NeedsAnEnum::class);

try {
    $dealer->suit();
    check(false, 'an enum return was expected to be refused');
} catch (NoDefaultValue $refusal) {
    check(
        str_contains($refusal->getMessage(), 'suit'),
        'an undoublable return type is refused by name: ' . explode("\n", $refusal->getMessage())[0],
    );
}

Understudy::defaults(Suit::class, static fn(): Suit => Suit::Hearts);

check($dealer->suit() === Suit::Hearts, 'and a registration is how the test says which value it meant');

Understudy::reset();
