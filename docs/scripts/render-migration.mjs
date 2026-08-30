import { readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

// MIGRATION.md is the same document as the two migrating-* pages (plan §4).
//
// The plan called for a synchronicity rule in AGENTS.md. A rule is a thing a
// person has to remember; this is a thing the build checks — `--check` fails
// when the rendered output differs from what is committed, so the two can only
// drift for as long as it takes CI to notice.
//
// The transform is deliberately small: strip frontmatter, unwrap VitePress's
// ::: containers into blockquotes GitHub renders, and rewrite site-absolute
// links to the published site. Everything else is markdown that reads the same
// in both places.

const scriptsDir = dirname(fileURLToPath(import.meta.url))
const srcDir = join(scriptsDir, '..', 'src')
const target = join(scriptsDir, '..', '..', 'MIGRATION.md')

const SITE = 'https://rasuvaeff.github.io/understudy'

const CONTAINER_LABEL = {
    tip: 'Note',
    warning: 'Warning',
    danger: 'Warning',
    info: 'Note',
}

function render(relativePath) {
    const raw = readFileSync(join(srcDir, relativePath), 'utf8')
    const body = raw.replace(/^---\n[\s\S]*?\n---\n/, '')

    const out = []
    let inContainer = false
    let inFence = false

    for (const line of body.split('\n')) {
        if (line.startsWith('```')) inFence = !inFence

        if (!inFence) {
            const open = line.match(/^:::\s*(tip|warning|danger|info)\s*(.*)$/)
            if (open !== null) {
                const [, kind, title] = open
                out.push(`> **${title.trim() === '' ? CONTAINER_LABEL[kind] : title.trim()}**`, '>')
                inContainer = true
                continue
            }
            if (line.trim() === ':::' && inContainer) {
                inContainer = false
                continue
            }
            if (inContainer) {
                out.push(line.trim() === '' ? '>' : `> ${line}`)
                continue
            }
        }

        out.push(line)
    }

    // Site-absolute links become links to the published site. A link to a page
    // that is part of THIS document stays inside it, as an anchor.
    return out
        .join('\n')
        .replaceAll('(/guide/migrating-from-mockery)', '(#migrating-from-mockery)')
        .replaceAll('(/guide/migrating-from-phpunit)', '(#migrating-from-phpunit)')
        .replace(/\((\/(?:guide|cookbook|adapters|api)\/[^)]*)\)/g, `(${SITE}$1)`)
}

const rendered =
    `<!-- Generated from docs/src/guide/migrating-*.md by docs/scripts/render-migration.mjs.\n` +
    `     Edit those pages, then run \`make docs-migration\`. -->\n\n` +
    `# Migrating to understudy\n\n` +
    `Two guides, one per library you are coming from. Both are also published at\n` +
    `[${SITE}/](${SITE}/), where the cross-links resolve.\n\n` +
    `- [Migrating from Mockery](#migrating-from-mockery)\n` +
    `- [Migrating from PHPUnit](#migrating-from-phpunit)\n\n` +
    `---\n\n` +
    render('guide/migrating-from-mockery.md') +
    `\n---\n\n` +
    render('guide/migrating-from-phpunit.md')

if (process.argv.includes('--check')) {
    let current = ''
    try {
        current = readFileSync(target, 'utf8')
    } catch {
        process.stderr.write('MIGRATION.md is missing. Run `make docs-migration`.\n')
        process.exit(1)
    }

    if (current !== rendered) {
        process.stderr.write(
            'MIGRATION.md is out of date with docs/src/guide/migrating-*.md.\n' +
                'Run `make docs-migration` and commit the result.\n',
        )
        process.exit(1)
    }

    process.stdout.write('render-migration: MIGRATION.md is up to date.\n')
} else {
    writeFileSync(target, rendered)
    process.stdout.write(`render-migration: wrote ${target}\n`)
}
