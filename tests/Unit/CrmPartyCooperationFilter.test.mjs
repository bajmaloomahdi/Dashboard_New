import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { build } from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

// Render the actual page, Select and Table. Only unrelated application services
// and layout are replaced; SSR runs no effects, HTTP calls or database operations.
const root = fileURLToPath(new URL('../../', import.meta.url));
const result = await build({
    absWorkingDir: root,
    stdin: {
        contents: `export { default as Page } from './resources/js/Pages/Crm/Parties/Index.tsx';
            export { filterPartyCategories, emptyCategory } from './resources/js/Pages/Crm/Parties/categoryFilters.ts';
            export { setFixture } from 'crm-render-fixture';`,
        resolveDir: root,
        loader: 'tsx',
    },
    bundle: true,
    write: false,
    platform: 'node',
    format: 'cjs',
    jsx: 'automatic',
    packages: 'external',
    plugins: [{
        name: 'crm-party-render-fixtures',
        setup(builder) {
            const stubs = {
                'crm-render-fixture': `let fixture;
                    export function setFixture(value) { fixture = value; }
                    export function getFixture() { return fixture; }`,
                '@inertiajs/react': `import { getFixture } from 'crm-render-fixture';
                    export function usePage() { return { props: getFixture().props }; }
                    export const router = { visit() { throw new Error('Unexpected navigation'); } };`,
                MainLayout: `export default function MainLayout({ children }) { return children; }`,
                PartyFormModal: `export default function PartyFormModal() { return null; }`,
                NotificationModal: `export default function NotificationModal() { return null; }`,
                CrmDeleteButton: `export default function CrmDeleteButton() { return null; }`,
                ListViewToggle: `import { getFixture } from 'crm-render-fixture';
                    export function useListViewMode() { return [getFixture().viewMode, () => {}]; }
                    export default function ListViewToggle() { return null; }`,
                crmApi: `export function crmApi() { throw new Error('Unexpected HTTP request'); }`,
            };
            builder.onResolve({ filter: /./ }, (args) => {
                const key = args.path === '@inertiajs/react' || args.path === 'crm-render-fixture'
                    ? args.path
                    : args.path.split('/').at(-1);
                return Object.hasOwn(stubs, key) ? { path: key, namespace: 'fixture' } : undefined;
            });
            builder.onLoad({ filter: /.*/, namespace: 'fixture' }, (args) => ({ contents: stubs[args.path], loader: 'js' }));
        },
    }],
});
const compiled = { exports: {} };
new Function('require', 'module', 'exports', result.outputFiles[0].text)(
    createRequire(new URL('../../package.json', import.meta.url)), compiled, compiled.exports,
);
const { Page, setFixture, filterPartyCategories, emptyCategory } = compiled.exports;

const party = {
    PartyID: 1,
    DisplayName: 'طرف حساب آزمایشی',
    PartyNature: 'LEGAL',
    IsActive: true,
    CooperationEndedAt: null,
    ActiveBrandCount: 0,
    ActiveAddressCount: 0,
    ActiveContactCount: 0,
    ClassificationCount: 0,
};

function renderPage(viewMode, cooperationEnded, parties = [party]) {
    setFixture({
        viewMode,
        props: {
            parties,
            filters: { search: null, isActive: null, partyNature: null, cooperationEnded },
            canManage: false,
            canDelete: false,
            positions: [],
            contactRoles: [],
        },
    });
    return renderToStaticMarkup(React.createElement(Page));
}

// Extract the real Select root, retaining nested elements rather than inspecting JSX.
function selectMarkup(html, testId) {
    const start = new RegExp(`<([a-z][a-z0-9-]*)\\b[^>]*data-testid="${testId}"[^>]*>`).exec(html);
    assert.ok(start, `Rendered markup must contain ${testId}`);
    const tags = new RegExp(`<(/?)${start[1]}\\b[^>]*>`, 'g');
    tags.lastIndex = start.index;
    let depth = 0;
    for (let tag; (tag = tags.exec(html));) {
        depth += tag[1] ? -1 : 1;
        if (depth === 0) return html.slice(start.index, tags.lastIndex);
    }
    assert.fail(`Unclosed Select root: ${testId}`);
}

function assertSelect(html, testId, accessibleLabel, selectedLabel) {
    const markup = selectMarkup(html, testId);
    const inputs = markup.match(/<input\b[^>]*>/g) || [];
    assert.ok(inputs.some((input) => input.includes('role="combobox"') && input.includes(`aria-label="${accessibleLabel}"`)),
        `${testId} must render a labeled combobox`);
    if (selectedLabel !== undefined) {
        assert.ok(markup.includes(`title="${selectedLabel}"`), `${testId} must display ${selectedLabel}`);
    }
}

for (const [value, label] of [[null, 'همه'], [false, 'فعال'], [true, 'پایان‌یافته']]) {
    test(`cards render a labeled cooperation combobox with selected option ${label}`, () => {
        const html = renderPage('cards', value);
        assertSelect(html, 'cooperation-filter', 'وضعیت همکاری', label);
        assert.ok(!html.includes('data-testid="cooperation-filter-table"'));
    });

    test(`table cooperation filter is independent of toolbar selection ${label}`, () => {
        const html = renderPage('grid', value);
        assertSelect(html, 'cooperation-filter', 'وضعیت همکاری', label);
        const header = html.match(/<thead\b[^>]*>([\s\S]*?)<\/thead>/)?.[1];
        assert.ok(header, 'The grid must render an actual table header');
        assertSelect(header, 'cooperation-filter-table', 'وضعیت همکاری جدول');
        assert.ok(header.includes('وضعیت همکاری'));
    });
}

test('an empty filtered grid retains its cooperation combobox so users can change the filter', () => {
    const html = renderPage('grid', true, []);
    const header = html.match(/<thead\b[^>]*>([\s\S]*?)<\/thead>/)?.[1];
    assert.ok(header, 'An empty result must retain the table header');
    assertSelect(header, 'cooperation-filter-table', 'وضعیت همکاری جدول');
});

test('all five dropdown headers render multi-select comboboxes', () => {
    const header = renderPage('grid', null).match(/<thead\b[^>]*>([\s\S]*?)<\/thead>/)?.[1];
    assert.ok(header);
    for (const name of ['ماهیت جدول', 'گروه', 'زیرگروه', 'وضعیت رکورد جدول', 'وضعیت همکاری جدول']) {
        assert.match(header, new RegExp(`<input\\b(?=[^>]*role="combobox")(?=[^>]*aria-label="${name}")[^>]*>`));
    }
    assert.equal((header.match(/ant-select-multiple\b/g) || []).length, 5);
});

test('category filters use OR within columns, AND across columns, and match beyond the first page', () => {
    const rows = [
        ...Array.from({ length: 20 }, (_, id) => ({ id, groups: ['other'], subgroups: ['A'] })),
        { id: 20, groups: ['G1'], subgroups: ['A', 'C'] },
        { id: 21, groups: ['G2'], subgroups: ['B'] },
        { id: 22, groups: ['G1'], subgroups: ['C'] },
        { id: 23, groups: [], subgroups: [] },
    ];
    const filter = (type, activity) => filterPartyCategories(rows, { type, activity }, { type: (r) => r.groups, activity: (r) => r.subgroups });
    assert.deepEqual(filter(['G1', 'G2'], ['A', 'B']).map((r) => r.id), [20, 21]);
    assert.deepEqual(filter(['G1', 'G2'], ['B']).map((r) => r.id), [21]);
    assert.deepEqual(filter(['G1', 'G2'], []).map((r) => r.id), [20, 21, 22]);
    assert.deepEqual(filter([], [emptyCategory, 'C']).map((r) => r.id), [20, 22, 23]);
    assert.equal(filter([], []), rows);
    const groupSequence = [['G1'], ['G1', 'G2'], ['G2'], []];
    assert.deepEqual(groupSequence.map((type) => filter(type, []).length), [2, 3, 1, rows.length]);
});

test('nature, record status, and cooperation status combine independently', () => {
    const rows = [
        { nature: 'LEGAL', status: '0', cooperation: '0' },
        { nature: 'LEGAL', status: '1', cooperation: '1' },
        { nature: 'INDIVIDUAL', status: '0', cooperation: '1' },
    ];
    const getters = { nature: (r) => [r.nature], status: (r) => [r.status], cooperation: (r) => [r.cooperation] };
    assert.deepEqual(filterPartyCategories(rows, { nature: ['LEGAL'], status: ['0', '1'], cooperation: ['1'] }, getters), [rows[1]]);
});
