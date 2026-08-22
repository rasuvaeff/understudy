<?php

declare(strict_types=1);

namespace UnderstudySpike\Psalm\Fixtures;

use UnderstudySpike\Psalm\BookRepository;

use function UnderstudySpike\Psalm\when;

function brokenScenario(BookRepository $repo): void
{
    when(fn () => $repo->find(1))->returns('oops');
}
