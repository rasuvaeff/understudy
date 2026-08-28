<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Def;

interface Workspace
{
    public function logger(): Logger;

    public function auditor(): AuditedLogger;

    public function sealed(): NullLogger;

    public function counter(): \Countable;

    public function concrete(): Concrete;

    public function chained(): Chained;

    public function counted(): int;

    public function maybe(): ?Logger;

    public function optionalLogger(): ?Logger;

    /** Neither branch can be doubled and neither has a builtin default. */
    public function hopeless(): NullLogger|Sealed;

    /** A DNF union: the intersection is one branch, `null` the other. */
    public function eitherWay(): (Audited&Timestamped)|null;

    /** The same shape without a null branch to short-circuit on. */
    public function eitherOrText(): (Audited&Timestamped)|string;
}
