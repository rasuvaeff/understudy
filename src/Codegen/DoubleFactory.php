<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Codegen;

use Rasuvaeff\Understudy\Bypass\FileWrapper;
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

    /** @var array<class-string, \ReflectionClass<object>> */
    private static array $reflections = [];

    private function __construct() {}

    /**
     * One reflection per generated class rather than one per double: building
     * it costs more the more members the class has, and every double of a
     * contract is built from the same class.
     */
    public static function instantiate(Blueprint $blueprint): object
    {
        /** @var \ReflectionClass<object> $reflection */
        $reflection = self::$reflections[$blueprint->generatedClass] ??= new \ReflectionClass($blueprint->generatedClass);

        return $reflection->newInstanceWithoutConstructor();
    }

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

        if (!$reflection->isInterface()) {
            self::rejectUndoublableClass($reflection, $contract, $primary);
        }

        self::rejectAbstractPropertyHooks($reflection, $contract);

        return $reflection;
    }

    /**
     * A property hook declared without a body — the only kind an interface can
     * declare, and an `abstract` one on a class — is an abstract member like
     * any other, and the generated class has to implement it. It cannot: this
     * engine intercepts calls, and reading a property is not one. Left
     * unimplemented, the class is refused by PHP itself from inside `eval()`,
     * as a fatal error no caller can catch — so the refusal has to happen here,
     * naming the property, like every other target this generator declines.
     *
     * `isAbstract()` and `getHooks()` are PHP 8.4 members, called by name so
     * that an analyser running on 8.3 does not resolve a method the platform
     * does not have there yet. On 8.3 no property can be abstract, so there is
     * nothing to refuse.
     *
     * @param \ReflectionClass<object> $reflection
     * @param class-string             $contract
     */
    private static function rejectAbstractPropertyHooks(\ReflectionClass $reflection, string $contract): void
    {
        $isAbstract = 'isAbstract';
        $getHooks = 'getHooks';

        foreach ($reflection->getProperties() as $property) {
            if (!method_exists($property, $isAbstract) || $property->{$isAbstract}() !== true) {
                continue;
            }

            /** @var array<non-empty-string, mixed> $hooks */
            $hooks = $property->{$getHooks}();

            throw UnsupportedTarget::notDoublable(
                $contract,
                sprintf(
                    '`%s::$%s` is an abstract property hook (`$%s { %s}`), and a double has no way to implement '
                    . 'it: this engine intercepts calls, and reading a property is not one. Expose the value '
                    . 'through a method on the contract, or pass a real object.',
                    $property->getDeclaringClass()->getName(),
                    $property->getName(),
                    $property->getName(),
                    implode('', array_map(
                        static fn(string $hook): string => $hook . '; ',
                        array_keys($hooks),
                    )),
                ),
            );
        }
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
            throw UnsupportedTarget::notDoublable($contract, self::whyFinalStillStands($contract, $reflection));
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
     * Why a `final` is still standing, which is three different situations.
     *
     * Saying "bypass is not enabled" when it is enabled sends the reader to
     * fix the thing that is already right. Bypass rewrites a class as
     * `file://` hands it to PHP, so a source arriving another way is out of
     * reach no matter how it was asked for — and that is worth saying in the
     * message rather than leaving to be discovered.
     *
     * @param class-string          $contract
     * @param \ReflectionClass<object> $reflection
     */
    private static function whyFinalStillStands(string $contract, \ReflectionClass $reflection): string
    {
        $recipe = "- Preferred: if it implements an interface, double the interface.\n"
            . "- If it is a value object, prefer a real instance.\n"
            . "- If it is a concrete dependency you cannot change, enable bypass before the class is\n"
            . '  first loaded: Understudy::bypassFinals(' . self::shortName($contract) . "::class)\n"
            . '- Introducing an interface remains the cleanest long-term fix.';

        if (!FileWrapper::isInstalled()) {
            return "the class is final, and bypass is not enabled.\n" . $recipe;
        }

        if (!FileWrapper::covers($contract)) {
            return "the class is final, and bypass is enabled for other classes but not this one.\n"
                . '- Name it too: Understudy::bypassFinals(' . self::shortName($contract) . "::class)\n"
                . "- Or ask for every class with Understudy::bypassFinals(), before any of them loads.\n"
                . $recipe;
        }

        $file = $reflection->getFileName();

        $origin = match (true) {
            $file === false => 'it has no source file of its own',
            str_starts_with($file, 'phar://') => 'it was read out of a PHAR, and `phar://` is not `file://`',
            default => 'it was already loaded, from `' . $file . '`, before the wrapper was installed',
        };

        return "the class is final, and bypass was asked for it but could not reach it: {$origin}.\n"
            . "- Bypass rewrites a class as `file://` hands it to PHP. A source that arrives another way\n"
            . "  — out of a PHAR, through an OPcache preload, from eval(), or past another wrapper that\n"
            . "  owns `file://` — is out of reach whatever bypass was asked for.\n"
            . $recipe;
    }

    private static function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
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
