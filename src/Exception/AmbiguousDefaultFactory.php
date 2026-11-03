<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Exception;

/**
 * Two registered default factories are equally close to the requested type.
 *
 * @api
 */
final class AmbiguousDefaultFactory extends \LogicException implements UnderstudyError
{
    /**
     * Builds the error for equally close default factories.
     *
     * @param class-string            $requested
     * @param non-empty-list<class-string> $candidates
     */
    public static function between(string $requested, array $candidates): self
    {
        sort($candidates);

        $format = "More than one default factory is equally close to `%s`: %s.\n"
            . 'Register one for `%s` itself — resolution by distance is what keeps the answer independent '
            . 'of the order the factories were registered in, and a tie has no order to fall back on.';

        return new self(sprintf(
            $format,
            $requested,
            implode(', ', array_map(static fn(string $c): string => '`' . $c . '`', $candidates)),
            $requested,
        ));
    }
}
