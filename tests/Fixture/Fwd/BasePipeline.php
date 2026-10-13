<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Fwd;

/**
 * The parent half of the inline-forwarding fixture: it declares a `protected
 * readonly` property, which before PHP 8.4 can only be initialized from the
 * scope of the class that declares it. A state copy reaching it through the
 * double's own class writes from the wrong scope and fails there.
 */
abstract class BasePipeline
{
    public function __construct(protected readonly string $tag) {}

    public function tag(): string
    {
        return $this->tag;
    }
}
