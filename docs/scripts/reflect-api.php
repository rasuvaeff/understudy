<?php

declare(strict_types=1);

// Multi-root API reflection for the understudy family site (plan §6).
//
// Five source trees, one PSR-4 prefix. The engine maps
// Rasuvaeff\\Understudy\\ -> src/; the two runner adapters map the same
// prefix to their own src/ (their classes live under src/Testo/, src/PhpUnit/);
// the two analyser packages map a deeper prefix to a deeper directory, which
// resolves to the same (srcDir, nsPrefix) pair. So one prefix covers all five
// and the reference's shape ports unchanged — the plan expected per-root
// prefixes and the code did not need them.
//
// The engine is reflected from the LIVE working tree of this checkout — the
// site always documents the branch it is built from. The satellites are
// reflected from docs/.api-workspace/vendor/, which installs them from their
// published Packagist tags: a method added on a satellite's master will not
// appear here until that satellite releases. That is deliberate, and the price
// is that a release must trigger a site rebuild or the reference silently
// lags — hence the weekly cron in docs.yml.
//
// docs/.api-workspace exists SOLELY so this script can autoload all five src/
// trees through one vendor/autoload.php without the engine depending on its
// own satellites. Run via:
//   docker run --rm -v "<pkg>":/app -w /app/docs/.api-workspace composer:2 composer install
//   docker run --rm -v "<pkg>":/app -w /app composer:2 php docs/scripts/reflect-api.php > docs/scripts/api-snapshot.json
// The path repository for the engine resolves "../.." from .api-workspace, so
// mounting the package root is enough here — unlike the reference, which lives
// in a monorepo and needs the monorepo root at the same relative depth. If it
// ever does fall back to Packagist the failure is silent (it still "succeeds",
// just reflects a stale release), which is what the canary below is for.

// dirname(), not '__DIR__ . "/../.api-workspace"': the ownership check below
// compares this string against ReflectionClass::getFileName(), which PHP
// always normalizes — a literal ".." segment here would make an otherwise
// identical path compare unequal and silently drop every testo/phpunit
// class from the report (found live 2026-08-09: 84 core entries, 0 for
// either adapter, no error).
$workspaceDir = dirname(__DIR__) . '/.api-workspace';
$workspaceAutoload = $workspaceDir . '/vendor/autoload.php';

if (!is_file($workspaceAutoload)) {
    fwrite(STDERR, "Missing $workspaceAutoload — run `composer install` in docs/.api-workspace first.\n");
    exit(1);
}

require $workspaceAutoload;

use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\DocBlock\Tag;
use phpDocumentor\Reflection\DocBlock\Tags\Deprecated;
use phpDocumentor\Reflection\DocBlock\Tags\Param;
use phpDocumentor\Reflection\DocBlock\Tags\See;
use phpDocumentor\Reflection\DocBlock\Tags\Throws;
use phpDocumentor\Reflection\DocBlock\Tags\Var_;
use phpDocumentor\Reflection\Types\ContextFactory;

/** @return array{version: string|null, reference: string|null} */
function installedPackageMeta(string $workspaceDir, string $packageName): array
{
    $installedPath = $workspaceDir . '/vendor/composer/installed.json';
    if (!is_file($installedPath)) {
        return ['version' => null, 'reference' => null];
    }

    $installed = json_decode(file_get_contents($installedPath), true, flags: JSON_THROW_ON_ERROR);
    $packages = $installed['packages'] ?? $installed; // composer v1 had no 'packages' wrapper

    foreach ($packages as $package) {
        if (($package['name'] ?? null) === $packageName) {
            return [
                'version' => $package['version'] ?? null,
                'reference' => $package['source']['reference'] ?? $package['dist']['reference'] ?? null,
            ];
        }
    }

    return ['version' => null, 'reference' => null];
}

$coreDir = dirname(__DIR__, 2);

// The satellites carry a real version because they are installed from
// Packagist (composer/installed.json). The engine is reflected from this
// checkout, which has no such record — and "working tree" is the honest answer
// only while someone is running this locally. On a deployed page it is the one
// package the reader cannot place in time, so CI passes the version it is
// building: DOCS_UNDERSTUDY_VERSION, from `git describe`.
$coreVersion = ['version' => getenv('DOCS_UNDERSTUDY_VERSION') ?: 'working tree', 'reference' => null];

const SATELLITES = [
    'testo' => 'rasuvaeff/understudy-testo',
    'phpunit' => 'rasuvaeff/understudy-phpunit',
    'psalm' => 'rasuvaeff/understudy-psalm',
    'phpstan' => 'rasuvaeff/understudy-phpstan',
];

/**
 * @param array{version: string|null, reference: string|null} $coreVersion
 * @param array<string, array{version: string|null, reference: string|null}> $satelliteVersions
 *
 * @return list<array{label: string, srcDir: string, nsPrefix: string, repoBlob: string, version: string|null, reference: string|null}>
 */
function roots(string $coreDir, string $workspaceDir, array $coreVersion, array $satelliteVersions): array
{
    $ns = 'Rasuvaeff\\Understudy\\';

    $roots = [
        [
            'label' => 'core',
            'srcDir' => $coreDir . '/src',
            'nsPrefix' => $ns,
            'repoBlob' => 'https://github.com/rasuvaeff/understudy/blob/master/src/',
            'version' => $coreVersion['version'],
            'reference' => $coreVersion['reference'],
        ],
    ];

    foreach (SATELLITES as $label => $package) {
        $meta = $satelliteVersions[$label];
        $roots[] = [
            'label' => $label,
            'srcDir' => $workspaceDir . '/vendor/' . $package . '/src',
            'nsPrefix' => $ns,
            // The commit SHA, not the version string: composer's installed.json
            // reports the raw git tag, already "v"-prefixed, and prepending
            // another "v" produces a dead blob link. The reference is
            // unambiguous either way.
            'repoBlob' => 'https://github.com/' . $package . '/blob/' . ($meta['reference'] ?? 'master') . '/src/',
            'version' => $meta['version'],
            'reference' => $meta['reference'],
        ];
    }

    return $roots;
}

$satelliteVersions = [];
foreach (SATELLITES as $label => $package) {
    $satelliteVersions[$label] = installedPackageMeta($workspaceDir, $package);
}

// Canary. The engine must come from THIS checkout through the path repository;
// if composer ever falls back to Packagist, the reference silently documents a
// released version instead of the branch being built. A symlinked path install
// puts the engine's own src/ at the location reflection reports, so comparing
// the two catches the fallback with no marker file to maintain.
$installedEngineFile = $workspaceDir . '/vendor/rasuvaeff/understudy/src/Understudy.php';
if (!is_file($installedEngineFile) || realpath($installedEngineFile) !== realpath($coreDir . '/src/Understudy.php')) {
    fwrite(
        STDERR,
        "The API workspace did not install the engine from this checkout.\n"
        . "Expected " . $coreDir . "/src/Understudy.php, resolved "
        . (is_file($installedEngineFile) ? realpath($installedEngineFile) : '(missing)') . ".\n"
        . "Re-run `composer install` in docs/.api-workspace with the package root mounted.\n",
    );
    exit(1);
}

// Free functions are autoloaded through composer's `files` entry, not PSR-4,
// so they exist only if that entry was honoured. Four of them are the primary
// DSL — when(), expect(), expectSequence(), verify() — and a class-only
// reflection pass would omit them without any error at all.
if (!function_exists('Rasuvaeff\\Understudy\\when')) {
    fwrite(STDERR, "src/functions.php was not autoloaded — the free functions would be missing from the snapshot.\n");
    exit(1);
}

/** @return list<string> */
function findPhpFiles(string $dir): array
{
    $files = [];
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            $files = [...$files, ...findPhpFiles($path)];
        } elseif (str_ends_with($entry, '.php')) {
            $files[] = $path;
        }
    }

    return $files;
}

/**
 * Does this file declare a class, interface, enum or trait?
 *
 * Tokenized rather than pattern-matched: `class` also appears as `::class`, in
 * a string, and inside an anonymous-class expression, and none of those makes
 * the file a type declaration. ext-tokenizer is a hard requirement of the
 * package being reflected, so it is always there.
 */
function declaresType(string $path): bool
{
    foreach (token_get_all(file_get_contents($path)) as $token) {
        if (!is_array($token)) {
            continue;
        }
        if (in_array($token[0], [T_CLASS, T_INTERFACE, T_ENUM, T_TRAIT], true)) {
            return true;
        }
    }

    return false;
}

function classNameFromPath(string $srcDir, string $nsPrefix, string $path): string
{
    $relative = substr($path, strlen($srcDir) + 1, -4); // strip src/ prefix and .php suffix
    $relative = str_replace('/', '\\', $relative);

    return $nsPrefix . $relative;
}

function stripInlineTags(string $text): string
{
    // phpDocumentor's Description::render() does NOT expand inline
    // {@see X}/{@link X} markers into anything — it reproduces the tag
    // syntax verbatim in the rendered text (found live 2026-08-09: prose
    // came out as literal "{@see \Rasuvaeff\PropertyTesting\Shrinkable}").
    // Stripped down to the bare reference, a resolved `\Fully\Qualified\Name`
    // is exactly what generate-api.mjs's linkType() already knows how to
    // turn into a page link — no second resolution step needed on the JS side.
    return preg_replace('/\{@(?:see|link)\s+([^}]+)\}/', '$1', $text) ?? $text;
}

/**
 * Rewrites the `self`/`static` keywords a docblock is entitled to use into the
 * class they stand for.
 *
 * phpDocumentor resolves a reference against the surrounding namespace without
 * caring that the tail is a keyword, so `{@see self::Off}` inside
 * `Runner\ShrinkMode` arrives here as `\Rasuvaeff\PropertyTesting\Runner\self`
 * — a symbol that exists nowhere, links to nothing, and renders on the page as
 * the nonexistent `Runner\self`. A class may not be named `self` or `static`,
 * so a segment spelled that way can only be the keyword.
 *
 * @param mixed $value Any part of a parsed docblock; arrays are walked.
 * @param ?string $selfClass The class the keywords refer to; null leaves the text alone.
 * @return mixed The same shape, with the keywords resolved.
 */
function resolveSelfReferences(mixed $value, ?string $selfClass): mixed
{
    if ($selfClass === null) {
        return $value;
    }

    if (is_array($value)) {
        return array_map(static fn(mixed $item): mixed => resolveSelfReferences($item, $selfClass), $value);
    }

    if (!is_string($value)) {
        return $value;
    }

    // A callback, not a replacement string: a fully qualified name is nothing
    // but backslashes, and every one of them would have to be escaped against
    // preg_replace()'s own backreference syntax.
    return preg_replace_callback(
        '/\\\\(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*(?:self|static)\b/',
        static fn(): string => '\\' . $selfClass,
        $value,
    ) ?? $value;
}

function tagBody(Tag $tag): string
{
    // Every DocBlock tag renders its own body via __toString() — the `Tag`
    // interface all of them implement, known and Generic/unknown alike
    // (including a malformed one, InvalidTag) — so this is how
    // @template/@implements/@api/@psalm-* extension tags, none of which
    // phpDocumentor has a typed class for, get captured without special-casing.
    return trim((string) $tag);
}

/**
 * @return array{
 *     summary: string,
 *     description: string,
 *     params: array<string, array{type: string, description: string}>,
 *     throws: list<array{type: string, description: string}>,
 *     see: list<string>,
 *     deprecated: string|null,
 *     isApi: bool,
 *     extensionTags: array<string, list<string>>,
 * }
 */
function parseDocBlock(
    DocBlockFactory $factory,
    ContextFactory $contextFactory,
    ReflectionClass|ReflectionMethod|ReflectionClassConstant|ReflectionProperty|ReflectionFunction $reflector,
): array {
    $empty = [
        'summary' => '',
        'description' => '',
        'params' => [],
        'throws' => [],
        'see' => [],
        'deprecated' => null,
        'isApi' => false,
        'extensionTags' => [],
    ];

    $docComment = $reflector->getDocComment();
    if ($docComment === false || trim($docComment) === '') {
        return $empty;
    }

    try {
        // Without a Context, phpDocumentor resolves every short type name
        // (ArbitraryInterface, TValue, ...) against the GLOBAL namespace, so
        // `@param ArbitraryInterface<TValue> $arbitrary` renders as
        // `\ArbitraryInterface<\TValue>` instead of the real FQCN — found
        // live 2026-08-09 on Gen::draw()'s @param. createFromReflector()
        // reads the declaring class's file and its `use` statements to
        // resolve short names the way PHP itself would.
        // ContextFactory::createFromReflector() has no ReflectionFunction
        // case — it reaches for a declaring class that a free function does
        // not have. The catch below would then report every one of them as
        // undocumented, silently: four @api functions came back isApi=false
        // with no error at all. For a function the context is built from its
        // file and namespace instead, which is what createFromReflector()
        // does for a class anyway.
        $context = $reflector instanceof ReflectionFunction
            ? $contextFactory->createForNamespace(
                $reflector->getNamespaceName(),
                (string) file_get_contents((string) $reflector->getFileName()),
            )
            : $contextFactory->createFromReflector($reflector);
        $block = $factory->create($docComment, $context);
    } catch (\Throwable) {
        // A malformed docblock (rare, e.g. an unbalanced inline tag) or a
        // context-building failure must not take the whole reflection run
        // down — report it as undocumented rather than crash;
        // check-integrity.mjs's completeness gate (I.5.6) will flag the
        // empty summary.
        return $empty;
    }

    $params = [];
    $throws = [];
    $see = [];
    $deprecated = null;
    $isApi = false;
    $extensionTags = [];

    foreach ($block->getTags() as $tag) {
        $name = $tag->getName();

        if ($tag instanceof Param) {
            $params[$tag->getVariableName() ?? ''] = [
                'type' => $tag->getType() !== null ? (string) $tag->getType() : '',
                'description' => $tag->getDescription() !== null ? stripInlineTags(trim($tag->getDescription()->render())) : '',
            ];

            continue;
        }

        // A property's or a constant's `@var` is the same shape as a `@param`
        // with no variable name, and it carries the narrow type (`non-empty-
        // list<Phase>`) that the PHP declaration (`array`) cannot.
        if ($tag instanceof Var_) {
            $params[$tag->getVariableName() ?? ''] = [
                'type' => $tag->getType() !== null ? (string) $tag->getType() : '',
                'description' => $tag->getDescription() !== null ? stripInlineTags(trim($tag->getDescription()->render())) : '',
            ];

            continue;
        }

        if ($tag instanceof Throws) {
            $throws[] = [
                'type' => $tag->getType() !== null ? (string) $tag->getType() : '',
                'description' => $tag->getDescription() !== null ? stripInlineTags(trim($tag->getDescription()->render())) : '',
            ];

            continue;
        }

        if ($tag instanceof See) {
            $see[] = (string) $tag->getReference();

            continue;
        }

        if ($tag instanceof Deprecated) {
            $deprecated = stripInlineTags(trim((string) $tag->getVersion() . ' ' . ($tag->getDescription()?->render() ?? '')));

            continue;
        }

        if ($name === 'api') {
            $isApi = true;

            continue;
        }

        // Everything else — @template, @implements, @psalm-*, @since,
        // @internal, ... — kept verbatim, grouped by tag name, so the page
        // generator can render what it recognises and dump the rest as-is
        // instead of silently discarding it.
        $extensionTags[$name][] = tagBody($tag);
    }

    $parsed = [
        'summary' => stripInlineTags(trim($block->getSummary())),
        'description' => stripInlineTags(trim($block->getDescription()->render())),
        'params' => $params,
        'throws' => $throws,
        'see' => $see,
        'deprecated' => $deprecated,
        'isApi' => $isApi,
        'extensionTags' => $extensionTags,
    ];

    // A free function has no declaring class, so `self` cannot appear in its
    // docblock and there is nothing to resolve against.
    $selfClass = match (true) {
        $reflector instanceof ReflectionClass => $reflector->getName(),
        $reflector instanceof ReflectionFunction => null,
        default => $reflector->getDeclaringClass()->getName(),
    };

    /** @var array{summary: string, description: string, params: array<string, array{type: string, description: string}>, throws: list<array{type: string, description: string}>, see: list<string>, deprecated: string|null, isApi: bool, extensionTags: array<string, list<string>>} */
    return resolveSelfReferences($parsed, $selfClass);
}

function typeToString(?ReflectionType $type): ?string
{
    return $type?->__toString();
}

/**
 * The interface/parent declaration a method implements, or null when it
 * declares itself. `getPrototype()` throws instead of returning null when
 * there is none.
 */
function prototypeOf(ReflectionMethod $method): ?ReflectionMethod
{
    try {
        return $method->getPrototype();
    } catch (ReflectionException) {
        return null;
    }
}

/**
 * Fills a `#[Override]` implementation's empty documentation from the
 * declaration it implements, per-field.
 *
 * This codebase documents the contract on the interface and leaves the
 * implementations bare — twenty `Arbitrary\*::generate()` methods carry no
 * docblock at all because `ArbitraryInterface::generate()` says everything.
 * Without inheritance those pages render a bare signature, and the
 * completeness gate reports twenty findings whose only honest fix is
 * copy-pasting one docblock twenty times.
 *
 * Per-field, not all-or-nothing: an implementation that adds its own summary
 * but no `@param` descriptions keeps its summary and inherits the parameters.
 *
 * @param array<string, mixed> $own
 * @param array<string, mixed> $inherited
 * @return array<string, mixed>
 */
function inheritDoc(array $own, array $inherited): array
{
    if ($own['summary'] === '') {
        $own['summary'] = $inherited['summary'];
    }
    if ($own['description'] === '') {
        $own['description'] = $inherited['description'];
    }
    if ($own['throws'] === []) {
        $own['throws'] = $inherited['throws'];
    }

    foreach ($inherited['params'] as $name => $param) {
        if (($own['params'][$name]['description'] ?? '') === '') {
            $own['params'][$name] = [
                'type' => $own['params'][$name]['type'] ?? $param['type'],
                'description' => $param['description'],
            ];
        }
    }

    return $own;
}

/**
 * Whether the method's own source contains a `throw` — the input to the
 * completeness gate's "a method that throws documents `@throws`" rule
 * (docs/scripts/check-integrity.mjs). Reflection cannot answer this, and the
 * docblock cannot either: a missing `@throws` is exactly what the gate looks
 * for.
 *
 * Token-based, not a regex over the source slice: "throw" occurs in comments
 * and in message strings all over this codebase, and `T_THROW` is the only
 * occurrence that is a statement. Deliberately includes throws inside nested
 * closures — a closure a method hands to the runner still surfaces its
 * exception through that call, and the alternative (tracking closure scope)
 * would need a parser for a checker whose finding is "write a docblock line".
 */
function bodyThrows(ReflectionMethod $method): bool
{
    $file = $method->getFileName();
    $start = $method->getStartLine();
    $end = $method->getEndLine();

    if ($file === false || $start === false || $end === false) {
        return false; // internal or eval'd — nothing to read
    }

    $lines = file($file);
    if ($lines === false) {
        return false;
    }

    $source = '<?php ' . implode('', array_slice($lines, $start - 1, $end - $start + 1));

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && $token[0] === T_THROW) {
            return true;
        }
    }

    return false;
}

function defaultValueLiteral(ReflectionParameter $param): ?string
{
    if (!$param->isDefaultValueAvailable()) {
        return null;
    }

    $constName = $param->getDefaultValueConstantName();
    if ($constName !== null) {
        // getDefaultValueConstantName() reports the namespace-qualified name
        // PHP tries FIRST at runtime — even for an unqualified global constant
        // like `PHP_INT_MIN` used inside a namespaced file, which actually
        // falls through to the global constant because no such namespaced one
        // exists. Found live 2026-08-09 on IntArbitrary's constructor
        // defaults, which rendered as
        // "Rasuvaeff\PropertyTesting\Arbitrary\PHP_INT_MIN" — not a real
        // constant, and not what the source says.
        if (defined($constName)) {
            return $constName;
        }
        $globalName = substr($constName, strrpos($constName, '\\') + 1);
        if (defined($globalName)) {
            return $globalName;
        }

        return $constName; // unresolved either way; keep the raw form as a signal
    }

    $value = $param->getDefaultValue();

    return match (true) {
        is_string($value) => var_export($value, true),
        is_array($value) => $value === [] ? '[]' : var_export($value, true),
        // A `new Foo()` default var_export()s to a multi-line
        // `Foo::__set_state(array(...))` dump that grows with every property
        // the class gains. Inside a markdown table cell those newlines end the
        // cell, and the type names left stranded in prose (`list<string>`) are
        // then parsed as HTML tags — VitePress fails with "Element is missing
        // end tag". The signature says all a reader needs: the default is a
        // default-constructed instance.
        is_object($value) => 'new \\' . $value::class . '()',
        default => var_export($value, true),
    };
}

$factory = DocBlockFactory::createInstance();
$contextFactory = new ContextFactory();
$report = [];

$rootList = roots($coreDir, $workspaceDir, $coreVersion, $satelliteVersions);

foreach ($rootList as $root) {
    foreach (findPhpFiles($root['srcDir']) as $path) {
        // A file that declares no type declares functions. Resolving its
        // path-derived class name (src/functions.php -> …\\functions) would
        // make PSR-4 include the file a SECOND time, and PHP fatals on the
        // redeclare — the whole script dies before writing a byte. Those files
        // are the free-function pass's business, further down.
        //
        // "Already in get_included_files()" is NOT the test to use here, and
        // trying it cost five classes: reflecting one class autoloads the
        // types its signatures name, so by the time the walk reaches
        // WhenBuilder.php or Runtime/Mode.php the file is legitimately loaded
        // already. Tokenizing asks the actual question.
        if (!declaresType($path)) {
            continue;
        }

        $className = classNameFromPath($root['srcDir'], $root['nsPrefix'], $path);
        if (
            !class_exists($className)
            && !interface_exists($className)
            && !enum_exists($className)
            && !trait_exists($className)
        ) {
            continue;
        }

        $reflection = new ReflectionClass($className);
        // Only classes physically declared in THIS root's src/ belong to it —
        // the shared PSR-4 prefix means class_exists() alone can't attribute
        // ownership (all three autoload through one merged loader).
        if ($reflection->getFileName() !== $path) {
            continue;
        }

        $classDoc = parseDocBlock($factory, $contextFactory, $reflection);
        $relativePath = substr($path, strlen($root['srcDir']) + 1);

        $constructorParams = [];
        $constructor = $reflection->getConstructor();
        if ($constructor !== null && $constructor->getDeclaringClass()->getName() === $className) {
            $ctorDoc = parseDocBlock($factory, $contextFactory, $constructor);
            foreach ($constructor->getParameters() as $param) {
                $promoted = $param->isPromoted();
                $property = $promoted ? $reflection->getProperty($param->getName()) : null;
                $constructorParams[] = [
                    'name' => $param->getName(),
                    'type' => $ctorDoc['params'][$param->getName()]['type'] ?? typeToString($param->getType()) ?? '',
                    'description' => $ctorDoc['params'][$param->getName()]['description'] ?? '',
                    'default' => defaultValueLiteral($param),
                    'promoted' => $promoted,
                    'promotedVisibility' => $property?->isPublic() ? 'public' : ($property?->isProtected() ? 'protected' : 'private'),
                    'readonly' => $property?->isReadOnly() ?? false,
                ];
            }
        }

        $methods = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $className) {
                continue; // inherited, not this class's own contract
            }
            if ($method->isConstructor()) {
                continue; // reported above as constructorParams
            }
            if ($reflection->isEnum() && in_array($method->getName(), ['cases', 'from', 'tryFrom'], true)) {
                // PHP synthesises these on every enum, so reflection reports
                // them as the enum's own — but they are language built-ins with
                // no docblock to write, not authored API surface. Listing them
                // would also charge the completeness ratchet for prose nobody
                // can add.
                continue;
            }

            $methodDoc = parseDocBlock($factory, $contextFactory, $method);

            $inheritedFrom = null;
            $prototype = prototypeOf($method);
            if ($prototype !== null) {
                $prototypeDoc = parseDocBlock($factory, $contextFactory, $prototype);
                $merged = inheritDoc($methodDoc, $prototypeDoc);
                if ($merged !== $methodDoc) {
                    $inheritedFrom = $prototype->getDeclaringClass()->getName();
                    $methodDoc = $merged;
                }
            }

            $params = [];
            foreach ($method->getParameters() as $param) {
                $params[] = [
                    'name' => $param->getName(),
                    'type' => $methodDoc['params'][$param->getName()]['type'] ?? typeToString($param->getType()) ?? '',
                    'description' => $methodDoc['params'][$param->getName()]['description'] ?? '',
                    'default' => defaultValueLiteral($param),
                    'variadic' => $param->isVariadic(),
                ];
            }

            $methods[] = [
                'name' => $method->getName(),
                'static' => $method->isStatic(),
                'params' => $params,
                'returnType' => typeToString($method->getReturnType()),
                'summary' => $methodDoc['summary'],
                'description' => $methodDoc['description'],
                'throws' => $methodDoc['throws'],
                'throwsInBody' => bodyThrows($method),
                'inheritedFrom' => $inheritedFrom,
                'see' => $methodDoc['see'],
                'deprecated' => $methodDoc['deprecated'],
                'attributes' => array_map(static fn(ReflectionAttribute $a): string => $a->getName(), $method->getAttributes()),
                'startLine' => $method->getStartLine(),
            ];
        }

        $declaredProperties = [];
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getDeclaringClass()->getName() !== $className) {
                continue;
            }
            // A promoted parameter is already reported as a constructor param.
            // Matching on the *name* instead would also swallow a declared
            // property that merely shares a name with a plain constructor
            // parameter — which is exactly how a class exposes the resolved
            // form of an argument it takes in a looser one, and precisely the
            // thing the reference must show.
            if ($property->isPromoted()) {
                continue;
            }
            if ($reflection->isEnum() && in_array($property->getName(), ['name', 'value'], true)) {
                // The same language built-ins as cases()/from()/tryFrom() above:
                // PHP declares them on the enum itself, and no docblock for them
                // can be written where they appear.
                continue;
            }
            $propertyDoc = parseDocBlock($factory, $contextFactory, $property);
            // `@var` first: it carries the narrow type (`non-empty-list<Phase>`)
            // that the PHP declaration (`array`) cannot express. An empty string
            // is a `@var` with no type at all, which is no better than nothing.
            $documentedType = $propertyDoc['params']['']['type'] ?? '';
            $declaredProperties[] = [
                'name' => $property->getName(),
                'type' => $documentedType === '' ? typeToString($property->getType()) : $documentedType,
                'readonly' => $property->isReadOnly(),
                'summary' => $propertyDoc['summary'],
            ];
        }

        $constants = [];
        foreach ($reflection->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
            if ($constant->getDeclaringClass()->getName() !== $className) {
                continue;
            }
            $constDoc = parseDocBlock($factory, $contextFactory, $constant);
            $value = $constant->getValue();
            $constants[] = [
                'name' => $constant->getName(),
                'type' => $constDoc['params']['']['type'] ?? (is_array($value) ? 'array' : get_debug_type($value)),
                'summary' => $constDoc['summary'],
                'value' => is_scalar($value) || $value === null ? $value : null,
            ];
        }

        $enumCases = [];
        if ($reflection->isEnum()) {
            $enumReflection = new ReflectionEnum($className);
            $isBacked = $enumReflection->isBacked();
            foreach ($enumReflection->getCases() as $case) {
                // getCases() is typed to return ReflectionEnumUnitCase, but for a
                // backed enum every case PHP hands back is actually a
                // ReflectionEnumBackedCase (the subtype adding getBackingValue()).
                $enumCases[] = [
                    'name' => $case->getName(),
                    'backingValue' => $isBacked && $case instanceof ReflectionEnumBackedCase ? $case->getBackingValue() : null,
                ];
            }
        }

        $report[] = [
            'root' => $root['label'],
            'rootVersion' => $root['version'],
            'rootReference' => $root['reference'],
            'class' => $className,
            'kind' => match (true) {
                $reflection->isInterface() => 'interface',
                $reflection->isEnum() => 'enum',
                // The PHPUnit adapter's entire public surface is a trait, so
                // this is not a completeness nicety: without it that root
                // contributes nothing at all.
                $reflection->isTrait() => 'trait',
                default => 'class',
            },
            'isApi' => $classDoc['isApi'],
            'isAbstract' => $reflection->isAbstract() && !$reflection->isInterface(),
            'isThrowable' => $reflection->implementsInterface(\Throwable::class),
            'summary' => $classDoc['summary'],
            'description' => $classDoc['description'],
            'deprecated' => $classDoc['deprecated'],
            'see' => $classDoc['see'],
            'extensionTags' => $classDoc['extensionTags'],
            'extends' => ($reflection->getParentClass() ?: null)?->getName(),
            'implements' => $reflection->getInterfaceNames(),
            'attributes' => array_map(static fn(ReflectionAttribute $a): string => $a->getName(), $reflection->getAttributes()),
            'constructorParams' => $constructorParams,
            'publicProperties' => $declaredProperties,
            'publicMethods' => $methods,
            'constants' => $constants,
            'enumCases' => $enumCases,
            'sourceUrl' => $root['repoBlob'] . $relativePath . '#L' . $reflection->getStartLine(),
        ];
    }
}

// --- Free functions -------------------------------------------------------
//
// Not in the reference at all: property-testing-core has no free functions, so
// its pipeline is ReflectionClass end to end. understudy's primary DSL —
// when(), expect(), expectSequence(), verify() — is four free functions in
// src/functions.php, and a class-only pass drops them silently. Integrity
// check 2 iterates the snapshot, so it would have stayed green about them.
//
// They are attributed to a root the same way classes are: by the file they are
// declared in, compared against each root's src/ directory.
$functionReport = [];

foreach (get_defined_functions()['user'] as $lowerName) {
    $function = new ReflectionFunction($lowerName);
    $file = $function->getFileName();

    if ($file === false) {
        continue;
    }

    $owner = null;
    foreach ($rootList as $root) {
        if (str_starts_with($file, $root['srcDir'] . '/')) {
            $owner = $root;

            break;
        }
    }

    if ($owner === null) {
        continue;
    }

    $doc = parseDocBlock($factory, $contextFactory, $function);

    if (!$doc['isApi']) {
        continue;
    }

    $params = [];
    foreach ($function->getParameters() as $param) {
        $params[] = [
            'name' => $param->getName(),
            'type' => $doc['params'][$param->getName()]['type'] ?? typeToString($param->getType()) ?? '',
            'description' => $doc['params'][$param->getName()]['description'] ?? '',
            'default' => defaultValueLiteral($param),
            'variadic' => $param->isVariadic(),
        ];
    }

    $functionReport[] = [
        'root' => $owner['label'],
        'rootVersion' => $owner['version'],
        'rootReference' => $owner['reference'],
        'kind' => 'function',
        // The declared name, with its namespace as written — get_defined_functions()
        // lowercases everything, and a page keyed on "rasuvaeff\\understudy\\when"
        // would not match anything a reader or a link ever writes.
        'function' => $function->getNamespaceName() . '\\' . $function->getShortName(),
        'isApi' => true,
        'summary' => $doc['summary'],
        'description' => $doc['description'],
        'deprecated' => $doc['deprecated'],
        'see' => $doc['see'],
        'params' => $params,
        'returnType' => typeToString($function->getReturnType()),
        'throws' => $doc['throws'],
        'sourceUrl' => $owner['repoBlob'] . substr($file, strlen($owner['srcDir']) + 1) . '#L' . $function->getStartLine(),
    ];
}

usort($functionReport, static fn(array $a, array $b): int => $a['function'] <=> $b['function']);

// "Who implements this interface" — computed once over the whole report so
// an interface's page can list every implementer across all three roots,
// not just the ones declared in the same package.
$implementers = [];
foreach ($report as $entry) {
    foreach ($entry['implements'] as $interface) {
        $implementers[$interface][] = $entry['class'];
    }
}
foreach ($report as &$entry) {
    $entry['implementedBy'] = $implementers[$entry['class']] ?? [];
}
unset($entry);

usort($report, static fn(array $a, array $b): int => $a['class'] <=> $b['class']);

// A snapshot is now two collections, so it is an object rather than the
// reference's bare list. Consumers read $snapshot['classes'] and
// $snapshot['functions'].
echo json_encode(
    ['classes' => $report, 'functions' => $functionReport],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
) . "\n";
