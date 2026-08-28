<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Runtime;

use Rasuvaeff\Understudy\Exception\InvalidCallSpecification;
use Rasuvaeff\Understudy\Matcher\AnyRest;
use Rasuvaeff\Understudy\Matcher\TailMatcher;

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
     * The signal with the arity sentinels stripped, once the shape that made
     * the omission legitimate has been checked.
     *
     * A required parameter of a generated method defaults to the sentinel so a
     * specification may physically pass fewer arguments than the method
     * declares. That is only meaningful when the specification *said* the rest
     * does not matter — its last spelled argument is `Arg::rest()`. Every
     * other shape is refused here, by name, rather than becoming a
     * specification that silently never matches: without a tail matcher the
     * stripped prefix would demand an arity no materialized call ever has, and
     * `Arg::remaining()`/`Arg::none()` make claims about a variadic tail, not
     * about parameters left unspelled.
     */
    public function withoutAbsentArguments(): self
    {
        $first = array_search(Absent::Argument, $this->args, strict: true);

        if ($first === false) {
            return $this;
        }

        /** @var mixed $argument */
        foreach (array_slice($this->args, $first + 1, preserve_keys: true) as $position => $argument) {
            if (!$argument instanceof Absent) {
                // A named argument skipped over an earlier parameter.
                throw InvalidCallSpecification::omittedBeforeSpecified($this->method, $first, $position);
            }
        }

        $last = $first === 0 ? null : $this->args[$first - 1];

        if ($last instanceof TailMatcher && !$last instanceof AnyRest) {
            throw InvalidCallSpecification::omittedTailNeedsRest($this->method, $last->describe());
        }

        if (!$last instanceof AnyRest) {
            throw InvalidCallSpecification::incompleteSpecification($this->method, $first, count($this->args));
        }

        return new self($this->double, $this->method, array_slice($this->args, 0, $first));
    }
}
