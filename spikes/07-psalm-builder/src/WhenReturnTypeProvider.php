<?php

declare(strict_types=1);

namespace UnderstudySpike\Psalm;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Stmt\Return_;
use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\FunctionReturnTypeProviderInterface;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Union;

final class WhenReturnTypeProvider implements FunctionReturnTypeProviderInterface
{
    /**
     * @return list<lowercase-string>
     */
    public static function getFunctionIds(): array
    {
        return ['understudyspike\psalm\when'];
    }

    public static function getFunctionReturnType(FunctionReturnTypeProviderEvent $event): ?Union
    {
        $args = $event->getCallArgs();

        if (!isset($args[0])) {
            return null;
        }

        $inner = self::innerMethodCall($args[0]->value);

        if ($inner === null) {
            return null;
        }

        $innerType = $event->getStatementsSource()->getNodeTypeProvider()->getType($inner);

        if ($innerType === null) {
            return null;
        }

        return new Union([new TGenericObject(WhenBuilder::class, [$innerType])]);
    }

    /**
     * The specification closure must contain exactly one direct method call:
     * `fn () => $double->method(...)` or `function () { return $double->method(...); }`.
     */
    private static function innerMethodCall(Expr $callArg): ?MethodCall
    {
        if ($callArg instanceof ArrowFunction && $callArg->expr instanceof MethodCall) {
            return $callArg->expr;
        }

        if ($callArg instanceof Closure
            && count($callArg->stmts) === 1
            && $callArg->stmts[0] instanceof Return_
            && $callArg->stmts[0]->expr instanceof MethodCall
        ) {
            return $callArg->stmts[0]->expr;
        }

        return null;
    }
}
