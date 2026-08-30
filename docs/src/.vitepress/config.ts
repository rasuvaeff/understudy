import { defineConfig } from 'vitepress'

// Nav/sidebar are the single source of truth for the page set (plan §3).
// docs/scripts/check-integrity.mjs enforces both directions: every link here
// resolves to a file, and every hand-written page under src/ is reachable
// from here — an orphan page is an error, not a page that merely nobody
// linked yet.
//
// Two entries carry a rule that is not visible from the tree (plan §3.1):
// "Strict stubs" lives under Expectations and "Fiber isolation" under
// Lifecycle because both are core semantics. The adapter pages describe what
// the adapter does about them and link here; they do not restate them.
const sidebar = [
    {
        text: 'Intro',
        items: [
            { text: 'What is understudy', link: '/guide/intro/what-is-understudy' },
            { text: 'Getting started', link: '/guide/intro/getting-started' },
            { text: 'Concepts', link: '/guide/intro/concepts' },
        ],
    },
    {
        text: 'Doubles',
        items: [
            { text: 'Creating a double', link: '/guide/doubles/creating' },
            { text: 'Property hooks', link: '/guide/doubles/property-hooks' },
            { text: 'Doubling a final class', link: '/guide/doubles/final-classes' },
        ],
    },
    {
        text: 'Stubbing',
        items: [
            { text: 'Overview', link: '/guide/stubbing/index' },
            { text: 'Chaining behaviour', link: '/guide/stubbing/chaining' },
            { text: 'Argument matchers', link: '/guide/stubbing/matchers' },
            { text: 'Capturing arguments', link: '/guide/stubbing/capturing' },
        ],
    },
    {
        text: 'Expectations & verification',
        items: [
            { text: 'Overview', link: '/guide/expectations/index' },
            { text: 'Verifying after the fact', link: '/guide/expectations/verify' },
            { text: 'Has everything been described?', link: '/guide/expectations/nothing-else' },
            { text: 'Call order', link: '/guide/expectations/ordering' },
            { text: 'Strict stubs', link: '/guide/expectations/strict-stubs' },
        ],
    },
    { text: 'Modes', link: '/guide/modes' },
    { text: 'Defaults registry', link: '/guide/defaults' },
    { text: 'Wiring a subject', link: '/guide/wiring' },
    { text: 'Forwarding to a real object', link: '/guide/forwarding' },
    {
        text: 'Lifecycle',
        items: [
            { text: 'Phases, scopes and transcripts', link: '/guide/lifecycle/index' },
            { text: 'Retention and lean()', link: '/guide/lifecycle/retention' },
            { text: 'Retiring a double', link: '/guide/lifecycle/forget' },
            { text: 'Fiber isolation', link: '/guide/lifecycle/fibers' },
        ],
    },
    { text: 'Failure messages', link: '/guide/failure-messages' },
    {
        text: 'Migrating',
        items: [
            { text: 'From Mockery', link: '/guide/migrating-from-mockery' },
            { text: 'From PHPUnit', link: '/guide/migrating-from-phpunit' },
        ],
    },
    { text: 'Using Pest', link: '/guide/using-pest' },
    { text: 'Static analysis', link: '/guide/static-analysis' },
    { text: 'Performance', link: '/guide/performance' },
    { text: 'Security', link: '/guide/security' },
    { text: 'Examples', link: '/guide/examples' },
    {
        text: 'Cookbook',
        items: [
            { text: 'Overview', link: '/cookbook/index' },
            { text: 'A double that held a file handle', link: '/cookbook/retention' },
            { text: 'Two objects that look alike', link: '/cookbook/identity' },
            { text: 'A query between two ordered steps', link: '/cookbook/protocol' },
            { text: 'The spy that counted the wrong calls', link: '/cookbook/spy-counter' },
            { text: 'The stub nobody used', link: '/cookbook/strict-stubs' },
        ],
    },
    {
        text: 'Adapters',
        items: [
            { text: 'Testo', link: '/adapters/testo' },
            { text: 'PHPUnit', link: '/adapters/phpunit' },
            { text: 'Psalm plugin', link: '/adapters/psalm' },
            { text: 'PHPStan extension', link: '/adapters/phpstan' },
        ],
    },
]

const SITE_URL = 'https://rasuvaeff.github.io/understudy/'

export default defineConfig({
    title: 'understudy',
    description:
        'Test doubles for PHP 8.3+ specified by calling the method, not by naming it in a string: when(fn () => $repo->find(1))->returns($book). One engine, two runner adapters (Testo, PHPUnit) and two static-analysis packages (Psalm, PHPStan).',
    base: '/understudy/',
    cleanUrls: true,
    lastUpdated: true,
    sitemap: { hostname: SITE_URL },
    head: [
        ['link', { rel: 'icon', type: 'image/svg+xml', href: '/understudy/favicon.svg' }],
        ['meta', { name: 'theme-color', content: '#1E1B2E' }],
        ['meta', { property: 'og:type', content: 'website' }],
        ['meta', { property: 'og:site_name', content: 'understudy' }],
        ['meta', { name: 'twitter:card', content: 'summary' }],
    ],
    // Per-page canonical + Open Graph/Twitter title & description — VitePress's
    // static `head` above cannot vary per page.
    transformHead: ({ pageData, title, description }) => {
        const clean = pageData.relativePath.replace(/\.md$/, '').replace(/(^|\/)index$/, '$1')
        const url = SITE_URL + clean

        return [
            ['link', { rel: 'canonical', href: url }],
            ['meta', { property: 'og:title', content: title }],
            ['meta', { property: 'og:description', content: description }],
            ['meta', { property: 'og:url', content: url }],
            ['meta', { name: 'twitter:title', content: title }],
            ['meta', { name: 'twitter:description', content: description }],
        ]
    },
    themeConfig: {
        logo: '/logo-mark.svg',
        search: { provider: 'local' },
        nav: [
            { text: 'Guide', link: '/guide/intro/what-is-understudy' },
            { text: 'Migrating', link: '/guide/migrating-from-mockery' },
            { text: 'Cookbook', link: '/cookbook/index' },
            { text: 'Adapters', link: '/adapters/testo' },
        ],
        sidebar: { '/': sidebar },
        outlineTitle: 'On this page',
        socialLinks: [{ icon: 'github', link: 'https://github.com/rasuvaeff/understudy' }],
        editLink: {
            pattern: 'https://github.com/rasuvaeff/understudy/edit/master/docs/src/:path',
        },
    },
})
