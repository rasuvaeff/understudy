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
    /** Where every generated double's class lives, and nothing else does. */
    public const string GENERATED_NAMESPACE = __NAMESPACE__ . '\\Generated\\';

    /**
     * Whether this object is a double this factory made — asked by the facade
     * methods, which are handed an object rather than a closure and otherwise
     * cannot tell "never was a double" from "was one before a reset".
     */
    public static function isGenerated(object $double): bool
    {
        return str_starts_with($double::class, self::GENERATED_NAMESPACE);
    }

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
        // A list assembled programmatically can name the same contract twice,
        // and `implements A, A` does not compile — as a fatal out of `eval()`,
        // uncatchable and fatal to the whole run. The duplicate says nothing
        // the first mention did not, so it is dropped rather than refused.
        /** @var non-empty-list<class-string> $contracts */
        $contracts = array_values(array_unique($contracts));
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
        $properties = self::unifyAbstractHooks($targets);
        $generatedClass = 'Understudy_' . substr(hash('xxh128', $key), 0, 16);

        $fqcn = self::GENERATED_NAMESPACE . $generatedClass;

        if (!class_exists($fqcn, autoload: false)) {
            eval(self::render($generatedClass, $targets, $methods, $properties));
        }

        // Also the proof, for both the reader and static analysis, that eval
        // produced the class it was asked for.
        \assert(class_exists($fqcn, autoload: false));

        return self::$byGeneratedClass[$fqcn] = new Blueprint(
            $fqcn,
            $contracts,
            $methods,
            $targets[0]->isInterface() ? [] : PropertyDefaults::forTarget($targets[0]),
            $properties,
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
            self::rejectUndoublableInterface($contract);
        } else {
            self::rejectUndoublableClass($reflection, $contract, $primary);
        }

        return $reflection;
    }

    /**
     * The five interfaces the language forbids a userland class to implement.
     *
     * Every other refusal in this file is a considered `UnsupportedTarget`;
     * these used to walk past all of them and be answered by the compiler
     * instead, as a fatal error out of `eval()` — uncatchable by `try`, by an
     * adapter, by anything, and fatal to the whole suite run rather than to
     * one test. `DateTimeInterface` and `Throwable` are among the first
     * contracts anybody reaches for, a clock and an error, so the fatal was
     * not a corner.
     *
     * Not a property of being built in: `Iterator`, `IteratorAggregate`,
     * `Stringable` and `Countable` double perfectly well. It is these five
     * specifically, and each of them has a way through.
     *
     * @param class-string $contract
     */
    private static function rejectUndoublableInterface(string $contract): void
    {
        $reason = match (ltrim(strtolower($contract), '\\')) {
            'throwable' => 'PHP forbids a userland class to implement Throwable directly. Double a '
                . 'concrete exception class instead, or an interface of your own that extends none of it.',
            'unitenum', 'backedenum' => 'only an enum may implement it, and an enum cannot be doubled at all — '
                . 'its cases are the values themselves. Pass the case you need, or double an interface the '
                . 'enum implements.',
            'datetimeinterface' => 'PHP forbids a userland class to implement DateTimeInterface. Pass a real '
                . '\DateTimeImmutable, or put a clock interface of your own in front of it and double that.',
            'traversable' => 'PHP requires it to be reached through Iterator or IteratorAggregate. Double '
                . 'one of those — both work here — or an interface of yours that extends one.',
            default => null,
        };

        if ($reason !== null) {
            throw UnsupportedTarget::notDoublable($contract, $reason);
        }
    }

    /**
     * Collects the abstract hooked properties the generated class has to
     * declare — an interface property, or an `abstract` hooked one on a class.
     * Rendered rather than refused (until 0.4.0 they were refused outright):
     * this engine generates the class source, so it can declare the property
     * and put the dispatcher inside the hook, which no `__get`-based library
     * can — `__get` fires only for an *inaccessible* property, precisely not
     * the case once the contract declares it.
     *
     * A concrete hook on a class target is not collected: it is inherited and
     * keeps running the target's own code. `isAbstract()` and `getHooks()` are
     * PHP 8.4 members, called by name so that an analyser running on 8.3 does
     * not resolve a method the platform does not have there; on 8.3 no
     * property can be abstract, so the answer is empty.
     *
     * Two shapes stay refused, each with its reason: a readonly class target —
     * PHP requires a readonly class to be extended only by a readonly class,
     * and a hooked property cannot be readonly — and a `&get` hook, whose
     * by-reference contract a virtual dispatching property cannot keep.
     *
     * @param non-empty-list<\ReflectionClass<object>> $targets
     *
     * @return array<non-empty-string, PropertySignature>
     */
    private static function unifyAbstractHooks(array $targets): array
    {
        $isAbstract = 'isAbstract';
        $getHooks = 'getHooks';

        if (!method_exists(\ReflectionProperty::class, $isAbstract)) {
            return [];
        }

        $primary = $targets[0];
        /** @var array<non-empty-string, PropertySignature> $properties */
        $properties = [];

        foreach ($targets as $target) {
            foreach ($target->getProperties() as $property) {
                if (!$property->{$isAbstract}()) {
                    continue;
                }

                $name = $property->getName();

                if (!$primary->isInterface() && $primary->isReadOnly()) {
                    throw UnsupportedTarget::notDoublable(
                        $primary->getName(),
                        sprintf(
                            'the class is readonly and `%s::$%s` is an abstract property hook. A readonly class '
                            . 'may only be extended by a readonly class, and a hooked property cannot be readonly '
                            . '— so no double can implement it. Double an interface of the contract instead.',
                            $property->getDeclaringClass()->getName(),
                            $name,
                        ),
                    );
                }

                /** @var array<non-empty-string, \ReflectionMethod> $hooks */
                $hooks = $property->{$getHooks}();
                $get = $hooks['get'] ?? null;

                if ($get !== null && $get->returnsReference()) {
                    throw UnsupportedTarget::notDoublable(
                        $target->getName(),
                        sprintf(
                            '`%s::$%s` declares a by-reference `&get` hook, and a double dispatches property '
                            . 'reads by value — the reference it handed back would not be the one the contract '
                            . 'promises. Expose the value through a method, or pass a real object.',
                            $property->getDeclaringClass()->getName(),
                            $name,
                        ),
                    );
                }

                $type = $property->getType();
                $signature = new PropertySignature(
                    name: $name,
                    type: $type === null ? '' : TypeRenderer::returnType($type, $property->getDeclaringClass()),
                    hasGet: $get !== null,
                    hasSet: isset($hooks['set']),
                );

                $existing = $properties[$name] ?? null;

                if ($existing === null) {
                    $properties[$name] = $signature;

                    continue;
                }

                // Property types are invariant, so two targets naming one
                // property must agree exactly; the hooks union.
                if ($existing->type !== $signature->type) {
                    throw UnsupportedTarget::notDoublable(
                        $target->getName(),
                        sprintf(
                            'property `$%s` is declared `%s` here and `%s` by another target. Property types '
                            . 'are invariant, so no single declaration satisfies both.',
                            $name,
                            $signature->type === '' ? '(untyped)' : $signature->type,
                            $existing->type === '' ? '(untyped)' : $existing->type,
                        ),
                    );
                }

                $properties[$name] = $existing->withHooksOf($signature);
            }
        }

        return $properties;
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
     * @param non-empty-list<\ReflectionClass<object>>    $targets
     * @param array<non-empty-string, MethodSignature>    $methods
     * @param array<non-empty-string, PropertySignature>  $properties
     */
    private static function render(string $generatedClass, array $targets, array $methods, array $properties): string
    {
        $primary = $targets[0];
        $extendsClass = !$primary->isInterface();
        $interfaces = array_slice($targets, $extendsClass ? 1 : 0);

        $body = '';

        foreach ($properties as $property) {
            $body .= self::renderProperty($property);
        }

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

    /**
     * A hooked property with the dispatcher inside the hook. Exactly the hooks
     * the contract declares are rendered: adding a `set` to a get-only
     * property would hand the code under test a write the contract never
     * promised, and PHP itself answers a write to the virtual property with
     * its own error. Neither hook touches the backing store, so the property
     * stays virtual and `PropertyDefaults` has nothing to collide with.
     *
     * The `set` hook uses the implicit `$value`, whose type PHP pins to the
     * property's own — nothing to render, nothing to get wrong.
     */
    private static function renderProperty(PropertySignature $property): string
    {
        $name = var_export($property->name, return: true);
        $hooks = [];

        if ($property->hasGet) {
            $hooks[] = sprintf('        get => \\%s::propertyRead($this, %s);', Runtime::class, $name);
        }

        if ($property->hasSet) {
            $hooks[] = sprintf(
                "        set {\n            \\%s::propertyWrite(\$this, %s, \$value);\n        }",
                Runtime::class,
                $name,
            );
        }

        return sprintf(
            "    public %s\$%s {\n%s\n    }\n\n",
            $property->type === '' ? '' : $property->type . ' ',
            $property->name,
            implode("\n", $hooks),
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
