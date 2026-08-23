<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Defaults;

/**
 * The constants the shapes in this directory inherit. Its own file, because
 * PSR-4 looks for a class where its name says it is — a rule that has now
 * broken three fixture sets in this package.
 */
abstract class ParentHolder
{
    public const string INHERITED = 'from-parent';

    protected const int HIDDEN_STEP = 7;
}
