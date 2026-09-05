<?php

declare(strict_types=1);

// Rule reflection for the two static-analysis satellites (plan §6.3).
//
// Neither package has a class-shaped public contract, and that is correct
// rather than a missing annotation: understudy-phpstan is registered through
// extension.neon (or phpstan/extension-installer) and understudy-psalm through
// `psalm-plugin enable`. A user never names one of their classes, so nothing
// in them is @api, and the class-based API reference would show an empty
// section for phpstan while integrity check 2 stayed vacuously green.
//
// What a user does write is an identifier — in `ignoreErrors`, or in a
// suppression. That is the contract, so that is what this reflects.
//
// Sources, in order of authority:
//   - extension.neon `rules:`      — which rules are actually registered
//   - the packages' PHP sources    — which identifiers exist, and where
//   - the declaring class docblock — what each one is for
//
// Run through the same workspace as reflect-api.php:
//   docker run --rm -v "<pkg>":/app -w /app composer:2 php docs/scripts/reflect-rules.php > docs/scripts/rules-snapshot.json

$workspaceDir = dirname(__DIR__) . '/.api-workspace';
$vendorDir = $workspaceDir . '/vendor';

if (!is_dir($vendorDir)) {
    fwrite(STDERR, "Missing $vendorDir — run `composer install` in docs/.api-workspace first.\n");
    exit(1);
}

/** @return list<string> */
function phpFiles(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }

    $files = [];
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            $files = [...$files, ...phpFiles($path)];
        } elseif (str_ends_with($entry, '.php')) {
            $files[] = $path;
        }
    }
    sort($files);

    return $files;
}

/**
 * The identifiers a file mentions, read from its string literals.
 *
 * Tokenized rather than regex-matched over the raw text: an identifier named
 * in a docblock or a comment is documentation, not a declaration, and only the
 * literal the code actually emits should put a row in the reference.
 *
 * Only a literal assigned to a constant whose name ends in `IDENTIFIER`
 * counts. The extension names its rule identifiers that way
 * (`SpecificationCheck::CLOSURE_IDENTIFIER`, `VoidReturnsRule::IDENTIFIER`),
 * and a string that merely starts with `understudy.` is not one of them:
 * `ClosureShape::RECEIVER = 'understudy.receiverOfCall'` is a php-parser
 * node attribute, and matching on the prefix alone put it on the rules page as
 * a sixth identifier a user could never write into `ignoreErrors`.
 *
 * @return list<string>
 */
function identifiersIn(string $path): array
{
    $found = [];
    $constant = null;
    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if ($token === ';') {
            $constant = null;
            continue;
        }
        if (!is_array($token)) {
            continue;
        }
        if ($token[0] === T_STRING) {
            $constant = $token[1];
            continue;
        }
        if ($token[0] !== T_CONSTANT_ENCAPSED_STRING || $constant === null || !str_ends_with($constant, 'IDENTIFIER')) {
            continue;
        }
        $value = trim($token[1], "'\"");
        if (preg_match('/^understudy\.[A-Za-z][A-Za-z0-9]*\z/', $value) === 1) {
            $found[] = $value;
        }
    }

    return array_values(array_unique($found));
}

/** The class a file declares, as an FQCN, or null. */
function declaredClass(string $path): ?string
{
    $source = (string) file_get_contents($path);
    $namespace = preg_match('/^namespace\s+([^;]+);/m', $source, $m) === 1 ? trim($m[1]) : '';
    if (preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $source, $m) !== 1) {
        return null;
    }

    return ($namespace === '' ? '' : $namespace . '\\') . $m[1];
}

/** The first prose line of a file's class-level docblock. */
function summaryOf(string $path): string
{
    $source = (string) file_get_contents($path);
    if (preg_match('#(/\*\*(?:[^*]|\*(?!/))*\*/)\s*(?:final\s+|abstract\s+|readonly\s+)*class\s#m', $source, $m) !== 1) {
        return '';
    }

    $lines = [];
    foreach (explode("\n", $m[1]) as $line) {
        $line = trim(preg_replace('#^\s*/?\*+/?#', '', $line) ?? '');
        if (str_starts_with($line, '@')) {
            break;
        }
        if ($line === '' && $lines !== []) {
            break;
        }
        if ($line !== '') {
            $lines[] = $line;
        }
    }

    return implode(' ', $lines);
}

/**
 * The rule classes extension.neon registers, in file order.
 *
 * @return list<string>
 */
function registeredRules(string $neonPath): array
{
    if (!is_file($neonPath)) {
        return [];
    }

    $rules = [];
    $inRules = false;
    foreach (explode("\n", (string) file_get_contents($neonPath)) as $line) {
        if (preg_match('/^rules:\s*$/', $line) === 1) {
            $inRules = true;

            continue;
        }
        if ($inRules && preg_match('/^\s+-\s*(\S+)\s*$/', $line, $m) === 1) {
            $rules[] = ltrim($m[1], '\\');

            continue;
        }
        if ($inRules && trim($line) !== '' && preg_match('/^\s/', $line) !== 1) {
            break; // a new top-level key ends the list
        }
    }

    return $rules;
}

$phpstanDir = $vendorDir . '/rasuvaeff/understudy-phpstan';
$psalmDir = $vendorDir . '/rasuvaeff/understudy-psalm';

$registered = registeredRules($phpstanDir . '/extension.neon');

// identifier -> the file that emits it. A rule class wins over an internal
// helper: SpecificationCheck emits three identifiers on behalf of the rules
// that run it, and attributing them to the helper would name a class no
// reader can act on.
$byIdentifier = [];
foreach (phpFiles($phpstanDir . '/src') as $path) {
    $class = declaredClass($path);
    foreach (identifiersIn($path) as $identifier) {
        $isRule = $class !== null && in_array($class, $registered, true);
        if (!isset($byIdentifier[$identifier]) || $isRule) {
            $byIdentifier[$identifier] = [
                'identifier' => $identifier,
                'class' => $class,
                'registered' => $isRule,
                'summary' => summaryOf($path),
                'file' => substr($path, strlen($phpstanDir) + 1),
            ];
        }
    }
}

ksort($byIdentifier);

$psalmIssues = [];
foreach (phpFiles($psalmDir . '/src') as $path) {
    $class = declaredClass($path);
    if ($class === null || !str_contains($class, '\\Issue\\')) {
        continue;
    }
    $psalmIssues[] = [
        'issue' => substr($class, strrpos($class, '\\') + 1),
        'class' => $class,
        'summary' => summaryOf($path),
        'file' => substr($path, strlen($psalmDir) + 1),
    ];
}

/** @return array{version: string|null, reference: string|null} */
function packageMeta(string $vendorDir, string $name): array
{
    $installed = json_decode(
        (string) file_get_contents($vendorDir . '/composer/installed.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    foreach ($installed['packages'] ?? [] as $package) {
        if (($package['name'] ?? null) === $name) {
            return [
                'version' => $package['version'] ?? null,
                'reference' => $package['source']['reference'] ?? $package['dist']['reference'] ?? null,
            ];
        }
    }

    return ['version' => null, 'reference' => null];
}

$phpstanMeta = packageMeta($vendorDir, 'rasuvaeff/understudy-phpstan');
$psalmMeta = packageMeta($vendorDir, 'rasuvaeff/understudy-psalm');

echo json_encode([
    'phpstan' => [
        'version' => $phpstanMeta['version'],
        'reference' => $phpstanMeta['reference'],
        'repoBlob' => 'https://github.com/rasuvaeff/understudy-phpstan/blob/' . ($phpstanMeta['reference'] ?? 'master') . '/',
        'registeredRules' => $registered,
        'identifiers' => array_values($byIdentifier),
    ],
    'psalm' => [
        'version' => $psalmMeta['version'],
        'reference' => $psalmMeta['reference'],
        'repoBlob' => 'https://github.com/rasuvaeff/understudy-psalm/blob/' . ($psalmMeta['reference'] ?? 'master') . '/',
        'issues' => $psalmIssues,
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
