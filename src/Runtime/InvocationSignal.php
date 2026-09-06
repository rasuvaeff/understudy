<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Runtime;

use Rasuvaeff\Understudy\Codegen\DoubleFactory;
use Rasuvaeff\Understudy\Exception\InvalidCallSpecification;
use Rasuvaeff\Understudy\Matcher\AnyRest;
use Rasuvaeff\Understudy\Matcher\ArgumentMatcher;
use Rasuvaeff\Understudy\Matcher\TailMatcher;
use Rasuvaeff\Understudy\Matcher\Unspelled;
use Rasuvaeff\Understudy\Matcher\UnspelledTail;

/**
 * Thrown by a generated method during a recording phase so that the call
 * aborts before it has to produce a value. Throwing is what lets a method
 * declared `: ?Book` — or even `: never` — hand its name and arguments back
 * to `when()` without violating its own return type.
 *
 * Never escapes the library: `when()`/`expect()`/`verify()` catch it.
 *
 * @internal
 */
final class InvocationSignal extends \Exception
{
    /** How deep a specification argument is searched for a stray matcher. */
    private const int NESTING_DEPTH = 8;

    /**
     * @param non-empty-string $method
     * @param list<mixed>      $args
     */
    public function __construct(
        public readonly object $double,
        public readonly string $method,
        public readonly array $args,
    ) {
        parent::__construct('understudy recording signal');
    }

    /**
     * The signal read as a specification: every sentinel resolved, or the
     * omission refused.
     *
     * A generated parameter defaults to the sentinel, so a specification may
     * physically pass fewer arguments than the method declares. What an
     * omission means depends on the contract:
     *
     * - a parameter the contract declares **optional** may be left out by any
     *   caller, so a specification that leaves it out says nothing about it.
     *   It becomes {@see Unspelled} in the middle of the argument list, and
     *   {@see UnspelledTail} where the list stops early — the specification
     *   then matches whatever the code under test passed there, which is what
     *   spelling nothing has to mean if arity is not to become a silent part
     *   of every specification.
     * - a parameter the contract declares **required** is present in every
     *   real call, so stopping before one is only meaningful when the
     *   specification said the rest does not matter — its last spelled
     *   argument is `Arg::rest()`. Every other shape is refused here, by name,
     *   rather than becoming a specification that silently never matches.
     */
    public function asSpecification(): self
    {
        foreach ($this->args as $position => $argument) {
            if (\is_array($argument)) {
                self::rejectNestedMatchers($this->method, $position, $argument, 0);
            }
        }

        if (!\in_array(Absent::Argument, $this->args, strict: true)) {
            return $this;
        }

        // The one nullable dereference: whether a position is optional is asked
        // three times below, and asking a signature that may be missing three
        // times reads as three different doubts about the same thing.
        $optional = DoubleFactory::blueprintOfGenerated($this->double::class)
            ?->method($this->method)
            ?->optionalParameters ?? [];
        $args = $this->args;
        $count = count($args);

        // Where the trailing run of sentinels begins — the arguments the
        // specification never reached, as opposed to a named argument that
        // jumped over a parameter in the middle.
        $tailFrom = $count;

        while ($tailFrom > 0 && $args[$tailFrom - 1] instanceof Absent) {
            --$tailFrom;
        }

        /** @var mixed $argument */
        foreach (array_slice($args, 0, $tailFrom, preserve_keys: true) as $position => $argument) {
            if (!$argument instanceof Absent) {
                continue;
            }

            if (!\array_key_exists($position, $optional)) {
                // A named argument skipped over a parameter no caller can
                // skip: the specification describes a call that cannot happen.
                throw InvalidCallSpecification::omittedBeforeSpecified(
                    $this->method,
                    $position,
                    $this->nextSpelled($args, $position),
                );
            }

            $args[$position] = new Unspelled();
        }

        if ($tailFrom === $count) {
            /** @var list<mixed> $args */
            return new self($this->double, $this->method, $args);
        }

        /** @var mixed $last */
        $last = $tailFrom === 0 ? null : $args[$tailFrom - 1];
        $args = array_slice($args, 0, $tailFrom);

        if ($last instanceof AnyRest) {
            return new self($this->double, $this->method, $args);
        }

        if ($last instanceof TailMatcher) {
            throw InvalidCallSpecification::omittedTailNeedsRest($this->method, $last->describe());
        }

        for ($position = $tailFrom; $position < $count; ++$position) {
            if (!\array_key_exists($position, $optional)) {
                throw InvalidCallSpecification::incompleteSpecification($this->method, $tailFrom, $count);
            }
        }

        $args[] = new UnspelledTail();

        return new self($this->double, $this->method, $args);
    }

    /**
     * Refuses a matcher buried inside an array argument.
     *
     * A literal array is compared by identity, so `find(['id' => Arg::any()])`
     * matches nothing at all — and says nothing about it, which is the failure
     * mode this library refuses everywhere else. `Arg::containing()` is the
     * matcher that describes part of an array, and it reads nested matchers.
     *
     * Depth is capped for the same reason a snapshot's is: `$a[] = &$a` is
     * legal PHP, and a walk that followed it would not return.
     *
     * @param non-empty-string        $method
     * @param array<array-key, mixed> $argument
     */
    private static function rejectNestedMatchers(string $method, int $position, array $argument, int $depth): void
    {
        if ($depth >= self::NESTING_DEPTH) {
            return;
        }

        /** @var mixed $value */
        foreach ($argument as $value) {
            if ($value instanceof ArgumentMatcher) {
                throw InvalidCallSpecification::matcherInsideArray($method, $position, $value->describe());
            }

            if (\is_array($value)) {
                self::rejectNestedMatchers($method, $position, $value, $depth + 1);
            }
        }
    }

    /**
     * The first position after `$from` the specification actually spelled.
     *
     * @param array<int, mixed> $args
     *
     * @return int<0, max>
     */
    private function nextSpelled(array $args, int $from): int
    {
        /** @var mixed $argument */
        foreach ($args as $position => $argument) {
            if ($position > $from && !$argument instanceof Absent) {
                \assert($position >= 0);

                return $position;
            }
        }

        \assert($from >= 0);

        return $from;
    }
}
