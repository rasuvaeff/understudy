<?php

/**
 * Spike 08 — inline forwarding through a reflection on the doubled class.
 *
 * Promises under test (what `Runtime::runInline()` rests on):
 * - a `ReflectionMethod` taken on a class enters THAT class's body even when
 *   the instance is a subclass overriding the method — the non-virtual entry
 *   `parent::` gives, without the double declaring anything;
 * - `$this` inside that body is the instance, so its own self-calls dispatch
 *   virtually and land in the subclass's override;
 * - a protected method is reachable the same way;
 * - a by-reference parameter still writes to the caller's variable when the
 *   argument array holds a reference;
 * - a body returning `$this` returns the subclass instance, so a fluent chain
 *   stays on the double.
 *
 * The alternative — generating a `__understudyReal_<method>()` sibling that
 * calls `parent::<method>()` — is what jasonmccreary/double does. It is not
 * open to us: every member added to a generated double is a name the doubled
 * type can no longer use.
 */

declare(strict_types=1);

namespace Understudy\Spikes\InlineForwarding;

use function Understudy\Spikes\assertSame;
use function Understudy\Spikes\assertTrue;

require __DIR__ . '/../lib.php';

class Doubled
{
    public function __construct(private readonly string $name = 'real') {}

    /** The template method: everything it does goes through itself. */
    public function run(string $input): string
    {
        return $this->apply($input);
    }

    public function apply(string $input): string
    {
        return $input . ':' . $this->name;
    }

    protected function hidden(): string
    {
        return 'hidden:' . $this->name;
    }

    public function fill(string &$slot, string $value): void
    {
        $slot = $value . '!';
    }

    public function self(): static
    {
        return $this;
    }
}

/** Stands in for the generated double: every method is an interception point. */
final class Generated extends Doubled
{
    /** @var list<string> */
    public array $seen = [];

    public function run(string $input): string
    {
        $this->seen[] = 'run';

        return 'intercepted run';
    }

    public function apply(string $input): string
    {
        $this->seen[] = 'apply';

        return 'intercepted apply';
    }

    protected function hidden(): string
    {
        $this->seen[] = 'hidden';

        return 'intercepted hidden';
    }
}

/**
 * @param list<mixed> $args
 */
function inline(object $double, string $class, string $method, array $args): mixed
{
    return (new \ReflectionMethod($class, $method))->invokeArgs($double, $args);
}

$double = new Generated();

// The real `run()` body ran — its own override was not entered — and the
// self-call inside it DID enter the override.
assertSame(
    inline($double, Doubled::class, 'run', ['in']),
    'intercepted apply',
    'a reflection on the doubled class enters its body, and the self-call inside it is intercepted',
);
assertSame($double->seen, ['apply'], 'only the self-call was intercepted, not the entry');

assertSame(
    inline($double, Doubled::class, 'apply', ['in']),
    'in:real',
    'the real body reads $this state copied onto the subclass instance',
);

assertSame(
    inline($double, Doubled::class, 'hidden', []),
    'hidden:real',
    'a protected body is reachable the same way',
);

$slot = 'empty';
$args = [&$slot, 'written'];
inline($double, Doubled::class, 'fill', $args);
assertSame($slot, 'written!', 'a by-reference parameter still writes to the caller variable');

assertTrue(
    inline($double, Doubled::class, 'self', []) === $double,
    'a body returning $this hands back the double, so a fluent chain stays doubled',
);

$unchanged = new Generated();
assertSame(
    $unchanged->run('in'),
    'intercepted run',
    'an ordinary call still goes to the override — the reflection is the only non-virtual entry',
);
