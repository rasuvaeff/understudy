<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Codegen;

use Rasuvaeff\Understudy\Exception\InvalidCallSpecification;
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

    /** @var array<class-string, Blueprint> */
    private static array $byGeneratedClass = [];

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
     * The blueprint a generated class was built from. Cloning is the one path
     * that starts from the object rather than from the contracts: `__clone`
     * runs on a copy nothing has registered yet, and the copy's own class is
     * all it has to go on.
     *
     * @param class-string $generatedClass
     */
    public static function blueprintOfGenerated(string $generatedClass): ?Blueprint
    {
        return self::$byGeneratedClass[$generatedClass] ?? null;
    }

    /**
     * @param non-empty-list<class-string> $contracts
     */
    private static function compile(array $contracts, string $key): Blueprint
    {
        $targets = [];

        foreach ($contracts as $position => $contract) {
            $targets[] = self::reflect($contract, primary: $position === 0);
        }

        $methods = TargetUnifier::unify($targets);
        $generatedClass = 'Understudy_' . substr(hash('xxh128', $key), 0, 16);

        $fqcn = __NAMESPACE__ . '\\Generated\\' . $generatedClass;

        if (!class_exists($fqcn, autoload: false)) {
            eval(self::render($generatedClass, $targets, $methods));
        }

        // Also the proof, for both the reader and static analysis, that eval
        // produced the class it was asked for.
        \assert(class_exists($fqcn, autoload: false));

        return self::$byGeneratedClass[$fqcn] = new Blueprint(
            $fqcn,
            $contracts,
            $methods,
            $targets[0]->isInterface() ? [] : PropertyDefaults::forTarget($targets[0]),
        );
    }

    /**
     * @param class-string $contract
     *
     * @return \ReflectionClass<object>
     */
    private static function reflect(string $contract, bool $primary): \ReflectionClass
    {
        if (!interface_exists($contract) && !class_exists($contract) && !trait_exists($contract)) {
            throw UnsupportedTarget::missing($contract);
        }

        $reflection = new \ReflectionClass($contract);

        if ($reflection->isInterface()) {
            return $reflection;
        }

        self::rejectUndoublableClass($reflection, $contract, $primary);

        return $reflection;
    }

    /**
     * Everything a class target has to satisfy before a line of it is
     * generated. Each rejection names what to do instead: a double that cannot
     * intercept a method would run the target's real code over an object whose
     * constructor never ran, which is worse than not building it.
     *
     * @param \ReflectionClass<object> $reflection
     * @param class-string             $contract
     */
    private static function rejectUndoublableClass(\ReflectionClass $reflection, string $contract, bool $primary): void
    {
        if ($reflection->isEnum()) {
            throw UnsupportedTarget::notDoublable(
                $contract,
                'an enum cannot be extended. Its cases are the values themselves — pass the case you need, '
                . 'or double the interface the enum implements.',
            );
        }

        if (trait_exists($contract)) {
            throw UnsupportedTarget::notDoublable(
                $contract,
                'a trait has no instances of its own. Double the class that uses it, or the interface it helps implement.',
            );
        }

        if (!$primary) {
            throw UnsupportedTarget::notDoublable(
                $contract,
                'only the first target may be a class — PHP has single inheritance. Put the class first '
                . 'and keep the rest interfaces.',
            );
        }

        if ($reflection->isFinal()) {
            throw UnsupportedTarget::notDoublable(
                $contract,
                'a final class cannot be extended. Double the interface it implements, or introduce one; '
                . 'stripping final at load time is a separate, opt-in mechanism.',
            );
        }

        if ($reflection->isInternal()) {
            throw UnsupportedTarget::notDoublable(
                $contract,
                'an internal class carries state this engine cannot reason about — its constructor is skipped '
                . 'and its native handlers would still run. Wrap it behind an interface of your own.',
            );
        }

        if ($reflection->isAnonymous()) {
            throw UnsupportedTarget::notDoublable(
                $contract,
                'an anonymous class has no name to extend by. Give it one, or double the interface it implements.',
            );
        }

        self::rejectFinalMembers($reflection, $contract);
    }

    /**
     * A non-private final instance method cannot be overridden, so a call to it
     * would execute the target's own code against a double whose constructor
     * never ran. Rejecting the whole target is the honest answer: silently
     * leaving one method live is exactly the surprise a test double exists to
     * avoid.
     *
     * @param \ReflectionClass<object> $reflection
     * @param class-string             $contract
     */
    private static function rejectFinalMembers(\ReflectionClass $reflection, string $contract): void
    {
        $filter = \ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_PROTECTED;

        foreach ($reflection->getMethods($filter) as $method) {
            if (!$method->isFinal() || $method->isStatic() || $method->isConstructor()) {
                continue;
            }

            throw UnsupportedTarget::notDoublable(
                $contract,
                sprintf(
                    '`%s::%s()` is final and cannot be overridden, so a double would run the real method '
                    . 'against an object whose constructor never ran. Double the interface instead.',
                    $method->getDeclaringClass()->getName(),
                    $method->getName(),
                ),
            );
        }
    }

    /**
     * @param non-empty-list<\ReflectionClass<object>>  $targets
     * @param array<non-empty-string, MethodSignature> $methods
     */
    private static function render(string $generatedClass, array $targets, array $methods): string
    {
        $primary = $targets[0];
        $extendsClass = !$primary->isInterface();
        $interfaces = array_slice($targets, $extendsClass ? 1 : 0);

        $body = '';

        foreach ($methods as $signature) {
            $body .= self::renderMethod($signature);
        }

        $body .= self::renderClone();

        if ($extendsClass) {
            $body .= self::renderDestructor();
        }

        return sprintf(
            "namespace %s\\Generated;\n\n%sfinal class %s%s%s\n{\n%s}\n",
            __NAMESPACE__,
            // PHP requires a readonly class to be extended only by another
            // readonly class. The double declares no properties of its own, so
            // inheriting the restriction costs it nothing.
            $extendsClass && $primary->isReadOnly() ? 'readonly ' : '',
            $generatedClass,
            $extendsClass ? ' extends \\' . $primary->getName() : '',
            $interfaces === []
                ? ''
                : ' implements ' . implode(', ', array_map(
                    static fn(\ReflectionClass $target): string => '\\' . $target->getName(),
                    $interfaces,
                )),
            $body,
        );
    }

    /**
     * A copy of a double is a double of its own: same contracts, nothing else.
     * Carrying the original's expectations over would make one test's setup
     * apply to an object the code under test created, and carrying its call log
     * over would let a copy satisfy a verification the original never met.
     *
     * The clone itself is not recorded — `clone $double` is the test doing
     * bookkeeping, not the code under test making a call.
     */
    private static function renderClone(): string
    {
        return sprintf(
            "    public function __clone(): void\n    {\n        \\%s::adoptClone(\$this);\n    }\n\n",
            Runtime::class,
        );
    }

    /**
     * The target's destructor must not run: it would tear down state its
     * constructor never built. PHP forbids a return type here — declaring one
     * is a fatal, not an exception — so this one method is rendered by hand
     * rather than through the signature path.
     */
    private static function renderDestructor(): string
    {
        return "    public function __destruct()\n    {\n    }\n\n";
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
            $signature->static => sprintf(
                'throw \\%s::staticMethodCalled(%s);',
                InvalidCallSpecification::class,
                var_export($signature->name, return: true),
            ),
            $signature->returnsVoid, $signature->returnsNever => $dispatch . ';',
            // The one place a reference is taken. Returning `$slot->value`
            // from a `&` method points the caller at storage that outlives the
            // call; a local would be replaced by the next one.
            $signature->returnsReference => sprintf(
                "\$slot = \\%s::referenceSlot(\$this, %s, %s);\n\n        return \$slot->value;",
                Runtime::class,
                var_export($signature->name, return: true),
                $signature->arguments,
            ),
            default => 'return ' . $dispatch . ';',
        };

        return sprintf(
            "    %s%s function %s%s(%s): %s\n    {\n        %s\n    }\n\n",
            $signature->visibility,
            $signature->static ? ' static' : '',
            $signature->returnsReference ? '&' : '',
            $signature->name,
            $signature->parameters,
            $signature->returnType,
            $statement,
        );
    }
}
