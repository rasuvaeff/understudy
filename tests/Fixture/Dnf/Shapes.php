<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Dnf;

/**
 * Every shape a DNF return type can take, on one contract.
 *
 * @internal
 */
interface Shapes
{
    public function plain(): Alpha&Beta;

    public function withNull(): (Alpha&Beta)|null;

    public function withScalar(): (Alpha&Beta)|string;

    public function twoIntersections(): (Alpha&Beta)|(Gamma&Delta);

    public function withClassBranch(): (Alpha&Beta)|Gamma;

    public function afterAnUndoublableBranch(): Sealed|(Alpha&Beta);
}
