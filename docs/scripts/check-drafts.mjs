import { readdirSync, readFileSync, statSync } from 'node:fs'
import { dirname, join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'

// A stub page must not be able to reach the deployed site.
//
// The sidebar is the source of truth for the page set (plan §3), so every page
// it names exists from the first commit — including the ones nobody has written
// yet. That is deliberate, and it creates exactly one hazard: this repository
// publishes to rasuvaeff.github.io/understudy/ from master, so a branch merged
// mid-way through the writing pass would deploy placeholders under a public URL.
//
// So the build refuses. `docs:build` runs this first, which means a stub cannot
// be built, which means it cannot be uploaded by the Pages job either. There is
// no flag to skip it: an escape hatch for "just this once" is how placeholder
// text ships.
//
// While writing, `npx vitepress build src` still builds without the gate — the
// gate is on the packaged build, not on the tool.

const scriptsDir = dirname(fileURLToPath(import.meta.url))
const srcDir = join(scriptsDir, '..', 'src')

const MARKER = '<!-- DRAFT'

function walk(dir) {
    const out = []
    for (const entry of readdirSync(dir)) {
        // api/ is generated; .vitepress holds config and theme, not pages.
        if (entry === '.vitepress') continue
        const path = join(dir, entry)
        if (statSync(path).isDirectory()) out.push(...walk(path))
        else if (entry.endsWith('.md')) out.push(path)
    }

    return out
}

const drafts = walk(srcDir).filter((file) => readFileSync(file, 'utf8').includes(MARKER))

if (drafts.length > 0) {
    const list = drafts.map((file) => `  - ${relative(srcDir, file)}`).join('\n')
    process.stderr.write(
        `${drafts.length} page(s) are still drafts and the site cannot be built:\n${list}\n\n` +
            'Write the page and remove its DRAFT comment. The comment names the section\n' +
            'of README.md, a satellite README or the plan that the page comes from.\n',
    )
    process.exit(1)
}

process.stdout.write('check-drafts: no draft pages.\n')
