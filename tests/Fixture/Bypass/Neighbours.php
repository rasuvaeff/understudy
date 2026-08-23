<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Bypass;

/**
 * Two final classes in one file. The targeted mode has to open exactly one of
 * them; a transform that worked per file rather than per declaration would open
 * both, which is the difference between a scalpel and a hammer.
 *
 * PSR-4 cannot autoload either — that is the point: the scripts require the
 * file directly, which is what the wrapper sees.
 */
final class OpenedNeighbour
{
    public function label(): string
    {
        return 'opened';
    }
}

final class SealedNeighbour
{
    public function label(): string
    {
        return 'sealed';
    }
}
