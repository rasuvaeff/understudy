<?php

declare(strict_types=1);

namespace Understudy\Spikes\BypassFinals\Fixture;

final class FinalService
{
    public static function reportedFile(): string
    {
        return __FILE__;
    }

    public static function reportedDir(): string
    {
        return __DIR__;
    }

    public static function relativeIncludeValue(): string
    {
        return require __DIR__ . '/relative.php';
    }

    final public function seal(): string
    {
        return 'sealed';
    }
}

final class FinalSibling
{
}
