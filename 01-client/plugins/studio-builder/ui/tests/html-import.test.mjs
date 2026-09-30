// Phase 8B — HTML/CSS import in the builder: the pure helpers, the transport
// contract, the replace-draft flow through the sync engine (409 keeps local
// edits), the rendered dialog (source switch, commit gated on an analysis of
// exactly the current inputs) and the EN/FR string coverage.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as pk from '../src/core/packages.mjs';
import { createTransport } from '../src/core/api.mjs';
import { SyncEngine, STATUS } from '../src/core/sync.mjs';
import * as ops from '../src/core/operations.mjs';
import { t } from '../src/core/messages.mjs';
import { manifest, doc, section, heading, manualScheduler, fakeServer } from './helpers.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const PAGE = { id: 1, uuid: '11111111-1111-4111-8111-111111111111' };
const HTML = '<section><h1>Hi ✓</h1><p>Body</p></section>';

test('html import: source files are checked by UTF-8 byte size (512 KiB HTML, 128 KiB CSS) and never interpreted in the browser', () => {
  assert.equal(pk.MAX_HTML_BYTES, 524288);
  assert.equal(pk.MAX_CSS_BYTES, 131072);
  assert.deepEqual(pk.checkSourceText(HTML, pk.MAX_HTML_BYTES), { ok: true, text: HTML });
  assert.equal(pk.checkSourceText('   ', pk.MAX_HTML_BYTES).error, 'source_empty');
  assert.deepEqual(pk.checkSourceText('', pk.MAX_CSS_BYTES, { allowEmpty: true }), { ok: true, text: '' }, 'an empty CSS file is fine');
  assert.equal(pk.checkSourceText('é'.repeat(262145), pk.MAX_HTML_BYTES).error, 'source_too_large', '262,145 characters but 524,290 bytes');
  assert.equal(pk.byteLength('✓🎉'), 7);
  assert.equal(pk.slugify('Café — Menu ✓ 2026'), 'cafe-menu-2026');
});

test('html import: request bodies — create names title + slug; replace targets THIS page at the revision being viewed (no slug); the analysis key changes with any input', () => {
  const create = pk.htmlImportRequest({ html: HTML, css: 'h1{color:red}', title: ' Home ', slug: 'home', dryRun: true });
  assert.deepEqual(create, { html: HTML, css: 'h1{color:red}', dry_run: true, mode: 'create', title: 'Home', slug: 'home' });
  const replace = pk.htmlImportRequest({ html: HTML, mode: pk.MODES.REPLACE, page: PAGE, revisionId: 42, dryRun: false });
  assert.deepEqual(Object.keys(replace).sort(), ['css', 'dry_run', 'expected_revision_id', 'html', 'mode', 'target_page_id']);
  assert.equal(replace.target_page_id, 1);
  assert.equal(replace.expected_revision_id, 42);
  const key = pk.analysisKey(create);
  assert.equal(key, pk.analysisKey({ ...create, dry_run: false }), 'dry_run is not part of the key');
  for (const change of [{ html: HTML + ' ' }, { css: '' }, { title: 'Other' }, { slug: 'other' }]) {
    assert.notEqual(pk.analysisKey(pk.htmlImportRequest({ html: HTML, css: 'h1{color:red}', title: 'Home', slug: 'home', dryRun: true, ...change })), key, `changing ${Object.keys(change)[0]} invalidates the analysis`);
  }
  assert.equal(pk.canImport({ dry_run: true, can_commit: true }, key, key), true);
  assert.equal(pk.canImport({ dry_run: true, can_commit: false }, key, key), false);
  assert.equal(pk.canImport({ dry_run: true, can_commit: true }, key, pk.analysisKey({ ...create, css: 'x' })), false);
});

test('html import: transport — a same-origin JSON POST to import_html carrying the CSRF header; the 422 report is readable', async () => {
  const seen = [];
  const fetchImpl = async (url, init) => {
    seen.push({ url, init });
    return { status: 422, json: async () => ({ ok: false, error: { code: 'validation_error', message: '', details: { report: { dry_run: false, source_kind: 'html_css', committed: null } } } }) };
  };
  const tr = createTransport({ apiUrl: 'http://site.test/plugins/studio-builder/admin/api.php', csrfToken: 'tok', fetchImpl });
  const res = await tr.importHtml(pk.htmlImportRequest({ html: HTML, slug: 'x', dryRun: false }));
  assert.equal(seen[0].init.method, 'POST');
  assert.match(seen[0].url, /action=import_html$/);
  assert.equal(seen[0].init.headers['X-CSRF-Token'], 'tok');
  assert.equal(seen[0].init.headers['Content-Type'], 'application/json');
  assert.equal(seen[0].init.credentials, 'same-origin');
  assert.equal(JSON.parse(seen[0].init.body).html, HTML);
  assert.equal(pk.reportOf(res).source_kind, 'html_css');
});

function htmlServer(initial) {
  const server = fakeServer(initial, (d, o) => ops.applyLocal(d, o, { manifest }));
  server.transport.importHtml = async (body) => {
    server.calls.push(['import_html', JSON.parse(JSON.stringify(body))]);
    if (body.expected_revision_id !== server.currentId) {
      return { ok: false, status: 409, error: { code: 'concurrency_conflict', message: '', details: { current_revision_id: server.currentId } } };
    }
    server.externalEdit((d) => { d.sections = [section([heading('From HTML')], 'Imported')]; });
    const snap = await server.transport.document();
    return { ok: true, status: 201, data: { ...snap.data, deduplicated: false, report: { dry_run: false, source_kind: 'html_css', committed: { pages: [{ mode: 'replaced' }] } } } };
  };
  return server;
}

test('html import: replace-draft goes through the engine command path — pending edits saved first, expected_revision_id carried, the imported draft adopted into the editor store', async () => {
  const sched = manualScheduler();
  const server = htmlServer(doc([section([heading('Before')])]));
  const engine = new SyncEngine({ transport: server.transport, pageId: 1, schedule: sched.schedule, cancel: sched.cancel });
  engine.load((await server.transport.document()).data, manifest);
  engine.apply({ op: 'update_seo', payload: { seo: { title: 'Local edit' } } });
  const ok = await engine.command((base) => server.transport.importHtml({ ...pk.htmlImportRequest({ html: HTML, mode: pk.MODES.REPLACE, page: PAGE, revisionId: base.expected_revision_id, dryRun: false }), expected_revision_id: base.expected_revision_id }), { label: 'Imported' });
  assert.equal(ok, true);
  assert.deepEqual(server.calls.map((c) => c[0]), ['operations', 'import_html'], 'the local edit was saved before importing');
  assert.equal(server.calls[1][1].target_page_id, PAGE.id);
  assert.equal(engine.getSnapshot().status, STATUS.SAVED);
  assert.equal(engine.getSnapshot().working.sections[0].blocks[0].props.text, 'From HTML', 'the canvas/inspector now show the imported draft');
});

test('html import: a stale replace (409) enters the conflict state and keeps the editor state intact', async () => {
  const sched = manualScheduler();
  const server = htmlServer(doc([section([heading('Before')])]));
  const engine = new SyncEngine({ transport: server.transport, pageId: 1, schedule: sched.schedule, cancel: sched.cancel });
  engine.load((await server.transport.document()).data, manifest);
  server.externalEdit((d) => { d.seo.title = 'Someone else'; });
  const ok = await engine.command((base) => server.transport.importHtml({ ...pk.htmlImportRequest({ html: HTML, mode: pk.MODES.REPLACE, page: PAGE, revisionId: base.expected_revision_id, dryRun: false }), expected_revision_id: base.expected_revision_id }), {});
  assert.equal(ok, false);
  assert.equal(engine.getSnapshot().status, STATUS.CONFLICT);
  assert.equal(engine.getSnapshot().working.sections[0].blocks[0].props.text, 'Before');
});

test('html import: every new UI string has an English text, and the host page supplies each one through __() with a French entry', () => {
  const keys = ['import_source', 'import_source_package', 'import_source_html', 'import_html_hint', 'import_html_file', 'import_css_file', 'import_title', 'import_slug', 'import_html_conversion', 'source_empty', 'source_too_large'];
  const builder = readFileSync(join(here, '..', '..', 'admin', 'builder.php'), 'utf8');
  const fr = readFileSync(join(here, '..', '..', 'lang', 'fr.php'), 'utf8');
  for (const key of keys) {
    assert.notEqual(t(key), key, `English text for ${key}`);
    const m = builder.match(new RegExp(`'${key}'\\s*=>\\s*__\\('([a-z0-9_]+)', '((?:[^'\\\\]|\\\\.)*)'\\)`));
    assert.ok(m, `admin/builder.php passes ${key}`);
    assert.equal(m[2].replace(/\\'/g, "'"), t(key), `the host fallback equals the UI English for ${key}`);
    assert.match(fr, new RegExp(`'${m[1]}' =>`), `French entry ${m[1]}`);
  }
});

// ── Rendered dialog ────────────────────────────────────────────────────────
let entry = null;
let skipReason = null;
let esbuild = null;
try {
  esbuild = await import('esbuild');
} catch {
  skipReason = 'dev dependencies not installed (run npm ci in plugins/studio-builder/ui)';
}
if (esbuild) {
  const dir = mkdtempSync(join(tmpdir(), 'sbx-html-'));
  const outfile = join(dir, 'render.mjs');
  await esbuild.build({
    entryPoints: [join(here, 'fixtures', 'render-entry.jsx')],
    outfile, bundle: true, platform: 'node', format: 'esm', jsx: 'automatic',
    conditions: ['production'], define: { 'process.env.NODE_ENV': '"production"' }, logLevel: 'silent',
    banner: { js: "import { createRequire as __sbxCreateRequire } from 'node:module'; const require = __sbxCreateRequire(import.meta.url);" },
  });
  entry = await import(pathToFileURL(outfile).href);
  process.on('exit', () => rmSync(dir, { recursive: true, force: true }));
}

test('html import UI: the import tab offers a source switch; HTML/CSS shows the HTML file, optional CSS file, title and slug, with Analyse and "Import into draft" disabled until a file is analysed', { skip: skipReason || false }, () => {
  const pkgMode = entry.renderPackages({ manifest, permissions: { view: true, edit: true } });
  assert.match(pkgMode, /data-testid="import-source"/);
  assert.match(pkgMode, /Kohevo package \(\.json\)/);
  assert.match(pkgMode, /HTML\/CSS/);
  assert.ok(!/data-testid="html-source"/.test(pkgMode), 'package mode shows the package picker only');

  const html = entry.renderPackages({ manifest, permissions: { view: true, edit: true, tokens: true }, source: 'html' });
  assert.match(html, /data-testid="html-source"/);
  assert.match(html, /accept="\.html,\.htm,text\/html"/);
  assert.match(html, /accept="\.css,text\/css"/);
  assert.match(html, /Page title/);
  assert.match(html, /data-testid="html-slug"/);
  assert.match(html, /<button[^>]*disabled=""[^>]*data-testid="import-analyze"/, 'nothing to analyse without an HTML file');
  assert.match(html, /<button[^>]*disabled=""[^>]*data-testid="import-commit"[^>]*>Import into draft</);
  assert.ok(!/design tokens/.test(html), 'HTML import never touches design tokens');
  assert.match(html, /Replace this page&#x27;s draft/, 'the existing mode selector is reused');
});

test('html import UI: the shared report view shows the conversion counts for an html_css report', { skip: skipReason || false }, () => {
  const report = {
    dry_run: true, can_commit: true, source_kind: 'html_css',
    summary: { pages_count: 1, sections_count: 3, blocks_count: 6, errors_count: 0, warnings_count: 2 },
    conversion: { stripped_security: 4, unsupported_elements: 1, hidden_dropped: 2 },
    issues: [{ severity: 'warning', code: 'security_stripped', item: 'html', path: 'html:L3 body>script[1]', message: 'A <script> element was removed.' }],
    planned_actions: [{ action: 'create_page', slug: 'home' }],
  };
  const html = entry.renderPackages({ manifest, permissions: { view: true, edit: true }, source: 'html', report });
  assert.match(html, /data-can-commit="true"/);
  assert.match(html, /data-testid="html-conversion"[^>]*>4 element\(s\) removed for security, 1 unsupported element\(s\) and 2 hidden element\(s\) left out\./);
  assert.match(html, /data-code="security_stripped"/);
  assert.match(html, /\(html:L3 body&gt;script\[1\]\)/);
  assert.match(html, /Create draft page \/home/);
});
