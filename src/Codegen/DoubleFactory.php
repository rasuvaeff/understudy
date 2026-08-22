<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Codegen;

use Rasuvaeff\Understudy\Exception\UnsupportedTarget;
use Rasuvaeff\Understudy\Runtime\Runtime;

/**
 * Generates and evaluates one class per set of contracts, once per process.
 *
 * @internal
 */
final class DoubleFactory
{
    /** @var array<string, Blueprint> */
    private static array $blueprints = [];

    private function __construct() {}

    /**
     * @param non-empty-list<class-string> $contracts
     */
    public static function blueprintFor(array $contracts): Blueprint
    {
        $key = implode('|', $contracts);

        return self::$blueprints[$key] ??= self::compile($contracts, $key);
    }

    /**
     * @param non-empty-list<class-string> $contracts
     */
    private static function compile(array $contracts, string $key): Blueprint
    {
        $targets = array_map(self::reflect(...), $contracts);
        $methods = TargetUnifier::unify($targets);
        $generatedClass = 'Understudy_' . substr(hash('xxh128', $key), 0, 16);

        $fqcn = __NAMESPACE__ . '\\Generated\\' . $generatedClass;

        if (!class_exists($fqcn, autoload: false)) {
            eval(self::render($generatedClass, $contracts, $methods));
        }

        // Also the proof, for both the reader and static analysis, that eval
        // produced the class it was asked for.
        \assert(class_exists($fqcn, autoload: false));

        return new Blueprint($fqcn, $contracts, $methods);
    }

    /**
     * @param class-string $contract
     *
     * @return \ReflectionClass<object>
     */
    private static function reflect(string $contract): \ReflectionClass
    {
        if (!interface_exists($contract) && !class_exists($contract)) {
            throw UnsupportedTarget::missing($contract);
        }

        $reflection = new \ReflectionClass($contract);

        if (!$reflection->isInterface()) {
            throw UnsupportedTarget::notDoublable(
                $contract,
                'only interfaces can be doubled in this version. Double the interface it implements, '
                . 'or introduce one for the dependency you need to stand in for.',
            );
        }

        return $reflection;
    }

    /**
     * @param non-empty-list<class-string>             $contracts
     * @param array<non-empty-string, MethodSignature> $methods
     */
    private static function render(string $generatedClass, array $contracts, array $methods): string
    {
        $body = '';

        foreach ($methods as $signature) {
            $body .= self::renderMethod($signature);
        }

        return sprintf(
            "namespace %s\\Generated;\n\nfinal class %s implements %s\n{\n%s}\n",
            __NAMESPACE__,
            $generatedClass,
            implode(', ', array_map(static fn(string $c): string => '\\' . $c, $contracts)),
            $body,
        );
    }

    private static function renderMethod(MethodSignature $signature): string
    {
        $dispatch = sprintf(
            '\\%s::dispatch($this, %s, %s)',
            Runtime::class,
            var_export($signature->name, return: true),
            $signature->arguments,
        );

        // A `void` method must not return a value, and a `never` method must
        // not return at all — the dispatcher throws for it before returning.
        //
        // A by-reference method must not return a call's result directly
        // either: PHP can only bind a reference to a variable, and returning
        // an expression raises "Only variable references should be returned by
        // reference".
        $statement = match (true) {
            $signature->returnsVoid, $signature->returnsNever => $dispatch . ';',
            $signature->returnsReference => '$result = ' . $dispatch . ";\n\n        return \$result;",
            default => 'return ' . $dispatch . ';',
        };

        return sprintf(
            "    public function %s%s(%s): %s\n    {\n        %s\n    }\n\n",
            $signature->returnsReference ? '&' : '',
            $signature->name,
            $signature->parameters,
            $signature->returnType,
            $statement,
        );
    }
}
