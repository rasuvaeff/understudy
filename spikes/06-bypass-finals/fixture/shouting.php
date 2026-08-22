<?php

declare(strict_types=1);

// `final` is a case-insensitive keyword. This fixture keeps the pre-filter
// honest: with a case-sensitive check the strip below silently does nothing.

namespace Understudy\Spikes\BypassFinals\Fixture;

FINAL class ShoutingFinalService
{
}

Final class TitleCaseFinalService
{
}
