<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Wire;

/** The declared class is not loadable: nothing can be doubled, and `null` is allowed. */
class MissingClassSubject
{
    public function __construct(public readonly ?\Rasuvaeff\Understudy\Tests\Fixture\Wire\NoSuchCollaborator $collaborator) {}
}
