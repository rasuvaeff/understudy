<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

/**
 * Every spelling of a constant default whose rendered SOURCE matters — the
 * value alone cannot tell `= \Cfg::LIMIT` from `= 5`, and faithfulness to the
 * declared form is what the renderer promises. `SELF`/`PARENT` are uppercase
 * on purpose: Reflection preserves the case, and the resolver must not.
 */
class ConstantDefaults extends ConstantDefaultBase
{
    public const int MINE = 2;

    public function viaClass(int $a = KnownConstants::LIMIT): void {}

    public function viaSelfUpper(int $a = self::MINE): void {}

    public function viaParentUpper(int $a = parent::FROM_PARENT): void {}

    public function viaInterfaceConstant(string $a = ConstantsInterface::MODE): void {}
}
