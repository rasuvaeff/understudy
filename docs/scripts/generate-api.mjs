import { readFileSync, writeFileSync, mkdirSync, readdirSync, rmSync, existsSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

// Renders docs/scripts/api-snapshot.json (reflect-api.php, five roots) and
// docs/scripts/rules-snapshot.json (reflect-rules.php) into docs/src/api/**
// (plan §6). EN-only.
//
// Three kinds of page, because the family has three kinds of public contract:
// a class page per @api type, one page for the free functions that are the
// primary DSL, and one for the static-analysis identifiers — which are the
// whole contract of two of the five packages, neither of which has an @api
// class at all.

const scriptsDir = dirname(fileURLToPath(import.meta.url))
const docsDir = join(scriptsDir, '..')
const apiDir = join(docsDir, 'src', 'api')
const classesDir = join(apiDir, 'classes')

import { NAMESPACE_PREFIX, functionAnchor, pageLink, relativePath, shortName } from './api-pages.mjs'

const ROOT_ORDER = ['core', 'testo', 'phpunit', 'psalm', 'phpstan']
const ROOT_LABEL = {
    core: 'engine',
    testo: 'Testo adapter',
    phpunit: 'PHPUnit adapter',
    psalm: 'Psalm plugin',
    phpstan: 'PHPStan extension',
}
const ROOT_PACKAGE = {
    core: 'rasuvaeff/understudy',
    testo: 'rasuvaeff/understudy-testo',
    phpunit: 'rasuvaeff/understudy-phpunit',
    psalm: 'rasuvaeff/understudy-psalm',
    phpstan: 'rasuvaeff/understudy-phpstan',
}
const ROOT_REPO = Object.fromEntries(
    Object.entries(ROOT_PACKAGE).map(([root, pkg]) => [root, `https://github.com/${pkg.replace('rasuvaeff/', 'rasuvaeff/')}`]),
)

function shortenType(type) {
    if (type === undefined || type === null || type === '') {
        return 'mixed'
    }
    // Strip the shared namespace prefix only — collapsing every remaining
    // backslash would concatenate the sub-namespace into the class name
    // (Arbitrary\ArrayArbitrary -> "ArbitraryArrayArbitrary", found live
    // 2026-08-09 on ArbitraryInterface's "Implemented by" list).
    return type.replaceAll(NAMESPACE_PREFIX, '')
}

// A bare backslash-namespaced FQCN, optionally leading-backslash-prefixed:
// docblock-resolved types always have the leading backslash ("\Rasuvaeff\...",
// from stripInlineTags()'s {@see} unwrap); native ReflectionType::__toString()
// never does ("Rasuvaeff\PropertyTesting\StateMachine\Command") even though
// it is just as fully qualified. Both must match as ONE contiguous token —
// matching only from the first backslash split "Rasuvaeff" off as bare
// leading text in the no-prefix case (found live 2026-08-09, PostconditionViolation's
// $command param rendered as "Rasuvaeff`PropertyTesting\StateMachine\Command`").
const FQCN_RE = /\\?[A-Za-z_][A-Za-z0-9_]*(?:\\[A-Za-z_][A-Za-z0-9_]*)+/

// Renders a type/prose string as safe, single-purpose markdown: a lone FQCN
// becomes a link (or shortened code text if it has no @api page); anything
// more complex — a union, a psalm generic (`list<string>`, `Foo<Bar>`), a
// nullable `?Foo` — is left ENTIRELY inside one backtick span rather than
// letting individual pieces link while stray `<`/`>`/`|` sit as bare text.
// That split matters structurally, not just cosmetically: markdown-it treats
// a code span's content as opaque, but bare `<...>` in a table cell or
// paragraph is parsed as an HTML/Vue tag by VitePress's Vue SFC compiler —
// found live 2026-08-09, `list<string>` unwrapped crashed the whole build
// ("Element is missing end tag"), and a partially-linked generic
// (`ArbitraryInterface<TValue>` with only the two names linked) would have
// left its `<`/`>` exposed the same way.
// Whole-string forms linkType treats as "a single type name, try to link it":
// a namespaced FQCN (FQCN_RE) or a bare global identifier with no namespace
// at all (RuntimeException, Stringable, Throwable — every extends/implements
// value that isn't part of this family). The latter is deliberately NOT part
// of FQCN_RE itself: that shared regex also drives linkifyProse()'s
// free-text search, where a bare single word ("the", "value", "int") must
// NEVER match — only linkType's known-single-type-cell callers need it.
const WHOLE_TYPE_RE = new RegExp(`^(?:${FQCN_RE.source}|\\\\?[A-Za-z_][A-Za-z0-9_]*)$`)

// A type in a TABLE CELL. Markdown splits a row on every `|`, backticks
// included, so a union type (`string|null`, `list<Invocation>|null`) tears its
// own cell in two — and the tail lands outside the code span, where VitePress's
// Vue compiler reads `<Invocation>` as an unclosed tag and fails the whole
// build. Escaping the pipe is what keeps a union inside one cell.
function typeCell(type, apiPagesByClass) {
    return linkType(type, apiPagesByClass).replaceAll('|', '\\|')
}

function linkType(type, apiPagesByClass) {
    if (type === undefined || type === null || type === '') {
        return 'mixed'
    }

    const trimmed = type.trim()
    if (WHOLE_TYPE_RE.test(trimmed)) {
        const clean = trimmed.replace(/^\\/, '')
        const page = apiPagesByClass.get(clean)
        const label = shortenType(clean)
        return page !== undefined ? `[\`${label}\`](${page})` : `\`${label}\``
    }

    return `\`${shortenType(trimmed)}\``
}

// For prose (summaries/descriptions), unlike table-cell types, embedded FQCNs
// should still link even when surrounded by plain sentences — there is no
// competing "whole string is one type expression" case to protect against,
// and every match is already backslash-delimited so no bare `<`/`>` risk exists.
// Only OUTSIDE code. A docblock is free to quote a line of PHP in a code span
// or a fenced block, and rewriting an FQCN there produces nested backticks and
// a statement that no longer works: understudy's own expect() docblock quotes
// `use function Rasuvaeff\\Understudy\\expect as expectCall;`, which came out
// as "use function `expect` as expectCall;" — broken markdown around an import
// that would not resolve. The reference never hit this because none of its
// docblocks quote code containing a family FQCN.
// The inline alternative deliberately allows newlines: a docblock wraps at
// ~75 columns, so a quoted statement long enough to contain an FQCN is almost
// always split across two lines, and a newline-free pattern misses exactly the
// spans this exists to protect.
const CODE_SEGMENT_RE = /(```[\s\S]*?```|`[^`]*`)/g

function linkifyProse(text, apiPagesByClass) {
    return text
        .split(CODE_SEGMENT_RE)
        .map((segment, index) =>
            // split() with a capturing group alternates: even indices are the
            // text between matches, odd indices are the code segments.
            index % 2 === 1
                ? segment
                : segment.replace(new RegExp(FQCN_RE.source, 'g'), (fqcn) => {
                      const clean = fqcn.replace(/^\\/, '')
                      const page = apiPagesByClass.get(clean)
                      const label = shortenType(clean)

                      return page !== undefined ? `[\`${label}\`](${page})` : `\`${label}\``
                  }),
        )
        .join('')
}

function formatParams(params) {
    return params.map((p) => `${shortenType(p.type)} $${p.name}${p.default !== null ? ` = ${p.default}` : ''}`).join(', ')
}

function methodSignature(method) {
    const prefix = method.static ? 'static ' : ''
    const returns = shortenType(method.returnType)
    return `${prefix}${method.name}(${formatParams(method.params)}): ${returns}`
}

// A fenced ```php block reads far better than an inline-code heading once a
// signature has enough params to wrap mid-identifier.
function methodSignatureBlock(method) {
    const oneLine = methodSignature(method)
    if (method.params.length <= 2 && oneLine.length <= 88) {
        return oneLine
    }
    const prefix = method.static ? 'static ' : ''
    const returns = shortenType(method.returnType)
    const params = method.params.map((p) => `    ${shortenType(p.type)} $${p.name}${p.default !== null ? ` = ${p.default}` : ''},`).join('\n')
    return `${prefix}${method.name}(\n${params}\n): ${returns}`
}

function firstSentence(text, maxLen) {
    const flat = text.replace(/\s+/g, ' ').trim()
    if (flat === '') return ''
    const boundary = flat.slice(0, maxLen + 1).search(/[.!?](\s|$)/)
    if (boundary !== -1 && boundary <= maxLen) return flat.slice(0, boundary + 1)
    if (flat.length <= maxLen) return flat
    const truncated = flat.slice(0, maxLen - 1)
    const lastSpace = truncated.lastIndexOf(' ')
    return (lastSpace > 0 ? truncated.slice(0, lastSpace) : truncated).trimEnd() + '…'
}

// stripInlineTags() in reflect-api.php unwraps {@see X}/{@link X} down to the
// bare `\Fully\Qualified\Name` — linkType() here is what turns that leftover
// reference into a page link (or shortened plain text), so prose and type
// signatures share one link-resolution path instead of two.
function renderProse(text, apiPagesByClass) {
    return linkifyProse(text, apiPagesByClass)
        .split('\n\n')
        .map((p) => p.trim())
        .filter(Boolean)
        .join('\n\n')
}

// The one function every piece of docblock free text MUST pass through
// before landing in a table cell or a single-line bullet: entity-escape
// `<`/`>` first (raw psalm-style generics like "array<string, Foo>" show up
// routinely in @param prose, not just in types — found live 2026-08-09 on
// Property's own $generators/$examples param descriptions, which crashed
// the build the same way `list<string>` did in a type column), THEN
// linkify FQCNs (backslash sequences are untouched by entity-escaping, so
// this order is safe), THEN neutralise `|`/newlines for the table syntax
// itself.
function escapeCell(text, apiPagesByClass) {
    const entitySafe = text.replace(/</g, '&lt;').replace(/>/g, '&gt;')
    const linked = apiPagesByClass !== undefined ? linkifyProse(entitySafe, apiPagesByClass) : entitySafe
    return linked.replace(/\|/g, '\\|').replace(/\n/g, ' ')
}

const BANNER = '<!-- AUTO-GENERATED by docs/scripts/generate-api.mjs from docs/scripts/api-snapshot.json (docs/scripts/reflect-api.php) — do not edit this file directly. -->'

function renderClass(entry, apiPagesByClass) {
    const name = shortName(entry.class)
    const rootLabel = ROOT_LABEL[entry.root]
    const kindLabel = { class: 'Class', interface: 'Interface', enum: 'Enum' }[entry.kind]
    const description = entry.summary ? firstSentence(entry.summary, 155) : `${name} — ${entry.kind} in the property-testing API reference (${rootLabel}).`

    const lines = []
    lines.push('---')
    lines.push(`title: "${name}"`)
    lines.push(`description: ${JSON.stringify(description)}`)
    lines.push('---')
    lines.push('')
    lines.push(BANNER)
    lines.push('')
    lines.push(`# \`${name}\``)
    lines.push('')
    lines.push(`\`${entry.class}\``)
    lines.push('')

    const badges = [`**${kindLabel}**`, `**Package:** [${ROOT_PACKAGE[entry.root]}](${ROOT_REPO[entry.root]})`, `[Source](${entry.sourceUrl})`]
    if (entry.rootVersion) {
        badges.push(`**Version:** ${entry.rootVersion}`)
    }
    lines.push(badges.join(' — '))
    lines.push('')

    if (entry.deprecated) {
        lines.push(`::: warning Deprecated`)
        lines.push(entry.deprecated)
        lines.push(':::')
        lines.push('')
    }

    if (entry.extends || entry.implements.length > 0 || entry.implementedBy.length > 0) {
        if (entry.extends) {
            lines.push(`**Extends:** ${linkType('\\' + entry.extends, apiPagesByClass)}`)
            lines.push('')
        }
        if (entry.implements.length > 0) {
            lines.push(`**Implements:** ${entry.implements.map((i) => linkType('\\' + i, apiPagesByClass)).join(', ')}`)
            lines.push('')
        }
        if (entry.implementedBy.length > 0) {
            lines.push(`**Implemented by:** ${entry.implementedBy.map((i) => linkType('\\' + i, apiPagesByClass)).join(', ')}`)
            lines.push('')
        }
    }

    const templateTags = entry.extensionTags?.template
    if (templateTags && templateTags.length > 0) {
        lines.push('**Type parameters:**')
        lines.push('')
        for (const t of templateTags) {
            lines.push(`- \`${t}\``)
        }
        lines.push('')
    }

    if (entry.summary) {
        lines.push(renderProse(entry.summary, apiPagesByClass))
        lines.push('')
    }
    if (entry.description) {
        lines.push(renderProse(entry.description, apiPagesByClass))
        lines.push('')
    }

    if (entry.see && entry.see.length > 0) {
        lines.push(`**See also:** ${entry.see.map((s) => `\`${s}\``).join(', ')}`)
        lines.push('')
    }

    if (entry.constants.length > 0) {
        lines.push('## Constants')
        lines.push('')
        lines.push('| Constant | Type | Value | Description |')
        lines.push('|---|---|---|---|')
        for (const c of entry.constants) {
            const value = c.value === null ? '' : `\`${JSON.stringify(c.value)}\``
            lines.push(`| \`${c.name}\` | \`${shortenType(c.type)}\` | ${value} | ${escapeCell(c.summary, apiPagesByClass)} |`)
        }
        lines.push('')
    }

    if (entry.enumCases.length > 0) {
        lines.push('## Cases')
        lines.push('')
        lines.push('| Case | Backing value |')
        lines.push('|---|---|')
        for (const c of entry.enumCases) {
            lines.push(`| \`${c.name}\` | ${c.backingValue === null ? '—' : `\`${JSON.stringify(c.backingValue)}\``} |`)
        }
        lines.push('')
    }

    if (entry.constructorParams.length > 0) {
        lines.push('## Constructor')
        lines.push('')
        lines.push('```php')
        lines.push('__construct(')
        for (const p of entry.constructorParams) {
            lines.push(`    ${shortenType(p.type)} $${p.name}${p.default !== null ? ` = ${p.default}` : ''},`)
        }
        lines.push(')')
        lines.push('```')
        lines.push('')
        lines.push('| Parameter | Type | Default | Description |')
        lines.push('|---|---|---|---|')
        for (const p of entry.constructorParams) {
            lines.push(`| \`$${p.name}\` | ${typeCell(p.type, apiPagesByClass)} | ${p.default !== null ? `\`${p.default}\`` : '*required*'} | ${escapeCell(p.description, apiPagesByClass)} |`)
        }
        lines.push('')
    }

    if (entry.publicProperties.length > 0) {
        lines.push('## Properties')
        lines.push('')
        lines.push('| Property | Type | Readonly | Description |')
        lines.push('|---|---|---|---|')
        for (const prop of entry.publicProperties) {
            lines.push(`| \`${prop.name}\` | \`${shortenType(prop.type)}\` | ${prop.readonly ? 'yes' : 'no'} | ${escapeCell(prop.summary ?? '', apiPagesByClass)} |`)
        }
        lines.push('')
    }

    if (entry.publicMethods.length > 0) {
        lines.push('## Methods')
        lines.push('')
        for (const method of entry.publicMethods) {
            lines.push(`### ${method.name}()`)
            lines.push('')
            lines.push('```php')
            lines.push(methodSignatureBlock(method))
            lines.push('```')
            lines.push('')
            if (method.deprecated) {
                lines.push(`::: warning Deprecated\n${method.deprecated}\n:::`)
                lines.push('')
            }
            if (method.summary) {
                lines.push(renderProse(method.summary, apiPagesByClass))
                lines.push('')
            }
            // reflect-api.php fills an #[Override] implementation's empty
            // docblock fields from the declaration it implements. Saying so
            // on the page is not decoration: the reader has to know the text
            // describes the contract, not this implementation's specifics.
            if (method.inheritedFrom) {
                lines.push(`*Documentation inherited from ${linkType('\\' + method.inheritedFrom, apiPagesByClass)}.*`)
                lines.push('')
            }
            const documentedParams = method.params.filter((p) => p.description !== '')
            if (documentedParams.length > 0) {
                for (const p of documentedParams) {
                    lines.push(`- \`$${p.name}\` — ${escapeCell(p.description, apiPagesByClass)}`)
                }
                lines.push('')
            }
            if (method.throws.length > 0) {
                lines.push('**Throws:**')
                lines.push('')
                for (const t of method.throws) {
                    lines.push(`- ${linkType(t.type.startsWith('\\') ? t.type : '\\' + t.type, apiPagesByClass)}${t.description ? ` — ${t.description}` : ''}`)
                }
                lines.push('')
            }
            if (method.description) {
                lines.push(renderProse(method.description, apiPagesByClass))
                lines.push('')
            }
        }
    }

    if (
        entry.publicProperties.length === 0 &&
        entry.publicMethods.length === 0 &&
        entry.constants.length === 0 &&
        entry.constructorParams.length === 0 &&
        entry.enumCases.length === 0
    ) {
        lines.push('No public members beyond what is documented above.')
        lines.push('')
    }

    return lines.join('\n')
}

function renderIndex(entries, functions, rules) {
    const lines = []
    lines.push('---')
    lines.push('title: API reference')
    lines.push('description: "Generated from reflection over all five packages\' src/ — the functions, the types and the analyser identifiers."')
    lines.push('---')
    lines.push('')
    lines.push(BANNER)
    lines.push('')
    lines.push('# API reference')
    lines.push('')
    lines.push(
        'Generated by reflection (`docs/scripts/reflect-api.php`) over all five packages\' `src/`, ' +
            'not written by hand — every signature, parameter and default value here is read straight ' +
            'from the code, not transcribed from it. Only `@api`-annotated types get a page; `@internal` ' +
            'classes are implementation detail and stay undocumented on purpose.',
    )
    lines.push('')
    lines.push('| Section | What is in it |')
    lines.push('|---|---|')
    lines.push(`| [Functions](/api/functions) | the ${functions.length} free functions that are the primary surface |`)
    lines.push('| [Exceptions](/api/exceptions) | every `@api` type that implements `Throwable` |')
    lines.push(
        `| [Static analysis rules](/api/rules) | the ${rules.phpstan.identifiers.length} PHPStan identifiers and the Psalm issue type — the whole contract of two packages that have no \`@api\` class |`,
    )
    lines.push('')

    for (const root of ROOT_ORDER) {
        const rootEntries = entries.filter((e) => e.root === root && e.isApi).sort((a, b) => a.class.localeCompare(b.class))
        if (rootEntries.length === 0) continue
        lines.push(`## ${ROOT_LABEL[root][0].toUpperCase()}${ROOT_LABEL[root].slice(1)}`)
        lines.push('')
        lines.push('| Type | Kind | Summary |')
        lines.push('|---|---|---|')
        for (const e of rootEntries) {
            const kindLabel = { class: 'class', interface: 'interface', enum: 'enum', trait: 'trait' }[e.kind]
            lines.push(`| [\`${shortName(e.class)}\`](${pageLink(e.class)}) | ${kindLabel} | ${escapeCell(firstSentence(e.summary, 100), apiPagesByClass)} |`)
        }
        lines.push('')
    }

    return lines.join('\n')
}

function renderExceptions(entries) {
    const lines = []
    lines.push('---')
    lines.push('title: Exceptions')
    lines.push('description: "Every exception type the family throws and which operation raises it."')
    lines.push('---')
    lines.push('')
    lines.push(BANNER)
    lines.push('')
    lines.push('# Exceptions')
    lines.push('')
    lines.push('Every `@api` type across all five packages that implements `Throwable`.')
    lines.push('')

    const throwables = entries.filter((e) => e.isApi && e.isThrowable).sort((a, b) => a.class.localeCompare(b.class))
    if (throwables.length === 0) {
        lines.push('None found in this snapshot.')
        lines.push('')
        return lines.join('\n')
    }

    lines.push('| Exception | Package | Extends | Summary |')
    lines.push('|---|---|---|---|')
    const apiPagesByClass = new Map(entries.filter((e) => e.isApi).map((e) => [e.class, pageLink(e.class)]))
    for (const e of throwables) {
        lines.push(
            `| [\`${shortName(e.class)}\`](${pageLink(e.class)}) | ${e.root} | ${e.extends ? typeCell(e.extends, apiPagesByClass) : '—'} | ${escapeCell(firstSentence(e.summary, 100), apiPagesByClass)} |`,
        )
    }
    lines.push('')

    return lines.join('\n')
}

function renderFunctions(functions, apiPagesByClass) {
    const lines = []
    lines.push('---')
    lines.push('title: Functions')
    lines.push('description: "The free functions that are understudy\'s primary surface: when(), expect(), expectSequence() and verify()."')
    lines.push('---')
    lines.push('')
    lines.push(BANNER)
    lines.push('')
    lines.push('# Functions')
    lines.push('')
    lines.push(
        'The free functions understudy declares in `src/functions.php`, autoloaded through ' +
            "composer's `files` entry. They are the primary surface: every example in this guide opens " +
            'with one. Each has a collision-free static twin on `Understudy` — see ' +
            '[Using Pest](/guide/using-pest) for when that matters.',
    )
    lines.push('')

    if (functions.length === 0) {
        lines.push('None found in this snapshot.')
        lines.push('')

        return lines.join('\n')
    }

    lines.push('| Function | Returns | Summary |')
    lines.push('|---|---|---|')
    for (const fn of functions) {
        const name = shortName(fn.function)
        lines.push(
            `| [\`${name}()\`](#${name.toLowerCase()}) | \`${shortenType(fn.returnType)}\` | ${escapeCell(firstSentence(fn.summary, 100), apiPagesByClass)} |`,
        )
    }
    lines.push('')

    for (const fn of functions) {
        const name = shortName(fn.function)
        lines.push(`## ${name}()`)
        lines.push('')
        lines.push(`\`${NAMESPACE_PREFIX}${name}()\` · [Source](${fn.sourceUrl})`)
        lines.push('')
        lines.push('```php')
        lines.push(signatureOf(fn))
        lines.push('```')
        lines.push('')
        if (fn.summary !== '') {
            lines.push(linkifyProse(fn.summary, apiPagesByClass))
            lines.push('')
        }
        if (fn.description !== '') {
            lines.push(linkifyProse(fn.description, apiPagesByClass))
            lines.push('')
        }
        if (fn.params.length > 0) {
            lines.push('| Parameter | Type | Description |')
            lines.push('|---|---|---|')
            for (const param of fn.params) {
                const label = `${param.variadic ? '...' : ''}$${param.name}${param.default === null ? '' : ` = ${param.default}`}`
                lines.push(
                    `| \`${label}\` | ${typeCell(param.type, apiPagesByClass)} | ${escapeCell(param.description, apiPagesByClass)} |`,
                )
            }
            lines.push('')
        }
        if (fn.throws.length > 0) {
            lines.push('**Throws**')
            lines.push('')
            for (const thrown of fn.throws) {
                lines.push(`- ${linkType(thrown.type, apiPagesByClass)}${thrown.description === '' ? '' : ` — ${escapeCell(thrown.description, apiPagesByClass)}`}`)
            }
            lines.push('')
        }
    }

    return lines.join('\n')
}

function signatureOf(fn) {
    const params = fn.params
        .map((p) => `${shortenType(p.type)} ${p.variadic ? '...' : ''}$${p.name}${p.default === null ? '' : ` = ${p.default}`}`)
        .join(', ')

    return `${shortName(fn.function)}(${params}): ${shortenType(fn.returnType)}`
}

function renderRules(rules) {
    const lines = []
    lines.push('---')
    lines.push('title: Static analysis rules')
    lines.push('description: "Every identifier the PHPStan extension reports and every issue type the Psalm plugin raises — the whole public contract of both packages."')
    lines.push('---')
    lines.push('')
    lines.push(BANNER)
    lines.push('')
    lines.push('# Static analysis rules')
    lines.push('')
    lines.push(
        'Neither analyser package has an `@api` class, and that is correct rather than a missing ' +
            'annotation: `understudy-phpstan` is registered through `extension.neon` and ' +
            '`understudy-psalm` through `psalm-plugin enable`, so a user never names one of their ' +
            'classes. What a user does write is an **identifier**, in `ignoreErrors` or a suppression. ' +
            'That is the contract, so that is what is reflected here.',
    )
    lines.push('')

    lines.push(`## PHPStan · \`${'rasuvaeff/understudy-phpstan'}\` ${rules.phpstan.version ?? ''}`.trimEnd())
    lines.push('')
    lines.push('| Identifier | Reported by | Summary |')
    lines.push('|---|---|---|')
    for (const id of rules.phpstan.identifiers) {
        const cls = id.class === null ? '—' : `[\`${id.class.slice(id.class.lastIndexOf('\\') + 1)}\`](${rules.phpstan.repoBlob}${id.file})`
        lines.push(`| \`${id.identifier}\` | ${cls} | ${id.summary.replaceAll('|', '\\|')} |`)
    }
    lines.push('')
    lines.push('Silence one by its identifier:')
    lines.push('')
    lines.push('```neon')
    lines.push('parameters:')
    lines.push('    ignoreErrors:')
    lines.push(`        - identifier: ${rules.phpstan.identifiers[0]?.identifier ?? 'understudy.matcherLeak'}`)
    lines.push('```')
    lines.push('')
    lines.push('**Registered rules** (from `extension.neon`, in file order):')
    lines.push('')
    for (const rule of rules.phpstan.registeredRules) {
        lines.push(`- \`${rule}\``)
    }
    lines.push('')

    lines.push(`## Psalm · \`rasuvaeff/understudy-psalm\` ${rules.psalm.version ?? ''}`.trimEnd())
    lines.push('')
    lines.push('| Issue type | Summary |')
    lines.push('|---|---|')
    for (const issue of rules.psalm.issues) {
        lines.push(`| [\`${issue.issue}\`](${rules.psalm.repoBlob}${issue.file}) | ${issue.summary.replaceAll('|', '\\|')} |`)
    }
    lines.push('')
    lines.push('The plugin reports its own findings under that one issue type; everything else it')
    lines.push("surfaces is Psalm's own diagnostic, made possible by the types the plugin fills in.")
    lines.push('')
    lines.push('For what each of these means in practice, see [Static analysis](/guide/static-analysis),')
    lines.push('[the Psalm plugin](/adapters/psalm) and [the PHPStan extension](/adapters/phpstan).')
    lines.push('')

    return lines.join('\n')
}

function clean(dir) {
    try {
        for (const entry of readdirSync(dir)) {
            rmSync(join(dir, entry), { recursive: true, force: true })
        }
    } catch {
        // directory does not exist yet
    }
}

const snapshotPath = join(scriptsDir, 'api-snapshot.json')
if (!existsSync(snapshotPath)) {
    console.error(`Missing ${snapshotPath} — run reflect-api.php first (see its header comment).`)
    process.exit(1)
}

const snapshot = JSON.parse(readFileSync(snapshotPath, 'utf8'))
// The snapshot is an object, not the reference's bare list: understudy has
// free functions and property-testing-core does not.
const entries = snapshot.classes
const functions = snapshot.functions
const apiEntries = entries.filter((e) => e.isApi)
const apiPagesByClass = new Map(apiEntries.map((e) => [e.class, pageLink(e.class)]))

mkdirSync(classesDir, { recursive: true })
clean(classesDir)

for (const entry of apiEntries) {
    const relPath = relativePath(entry.class)
    const outPath = join(classesDir, `${relPath}.md`)
    mkdirSync(dirname(outPath), { recursive: true })
    writeFileSync(outPath, renderClass(entry, apiPagesByClass) + '\n', 'utf8')
}

const rulesPath = join(scriptsDir, 'rules-snapshot.json')
if (!existsSync(rulesPath)) {
    console.error(`Missing ${rulesPath} — run reflect-rules.php first (see its header comment).`)
    process.exit(1)
}
const rules = JSON.parse(readFileSync(rulesPath, 'utf8'))

writeFileSync(join(apiDir, 'index.md'), renderIndex(entries, functions, rules) + '\n', 'utf8')
writeFileSync(join(apiDir, 'functions.md'), renderFunctions(functions, apiPagesByClass) + '\n', 'utf8')
writeFileSync(join(apiDir, 'exceptions.md'), renderExceptions(entries) + '\n', 'utf8')
writeFileSync(join(apiDir, 'rules.md'), renderRules(rules) + '\n', 'utf8')

console.log(
    `Generated ${apiEntries.length} API class pages into docs/src/api/classes/**, plus index.md, ` +
        `functions.md (${functions.length}), exceptions.md and rules.md (${rules.phpstan.identifiers.length} identifiers).`,
)
