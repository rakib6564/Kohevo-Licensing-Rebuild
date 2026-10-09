// Phase 8A — JSON package import/export in the builder: the pure helpers,
// the transport contract, the replace-draft flow through the sync engine
// (409 keeps local edits), and the rendered dialog (commit gated on a
// successful analysis of exactly the current inputs).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as pk from '../src/core/packages.mjs';
import { createTransport } from '../src/core/api.mjs';
import { SyncEngine, STATUS } from '../src/core/sync.mjs';
import * as ops from '../src/core/operations.mjs';
import { manifest, doc, section, heading, manualScheduler, fakeServer } from './helpers.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const PKG = { package_format: 'kohevo-studio-package', package_version: '1.0', exported_at: '2026-09-30T00:00:00Z', items: [{ kind: 'page' }] };
const PAGE = { id: 1, uuid: '11111111-1111-4111-8111-111111111111' };

test('packages: a chosen file is only accepted when it looks like a Kohevo Studio package', () => {
  assert.deepEqual(pk.parsePackageText(JSON.stringify(PKG)), { ok: true, package: PKG, items: 1 });
  assert.equal(pk.parsePackageText('').error, 'package_empty');
  assert.equal(pk.parsePackageText('{nope').error, 'package_not_json');
  assert.equal(pk.parsePackageText('[1,2]').error, 'package_not_studio');
  assert.equal(pk.parsePackageText(JSON.stringify({ ...PKG, package_format: 'other' })).error, 'package_not_studio');
  assert.equal(pk.parsePackageText('x'.repeat(pk.MAX_PACKAGE_BYTES + 1)).error, 'package_too_large');
});

test('packages: request bodies — create sends no target; replace targets THIS page at the revision being viewed; analysis key ignores dry_run', () => {
  const create = pk.importRequest({ pkg: PKG, dryRun: true });
  assert.deepEqual(Object.keys(create).sort(), ['dry_run', 'include_tokens', 'mode', 'package']);
  assert.equal(create.dry_run, true);
  const replace = pk.importRequest({ pkg: PKG, mode: pk.MODES.REPLACE, page: PAGE, revisionId: 42, dryRun: false });
  assert.equal(replace.target_page_id, PAGE.id);
  assert.equal(replace.expected_revision_id, 42);
  assert.equal(replace.dry_run, false);
  assert.equal(pk.analysisKey(pk.importRequest({ pkg: PKG, dryRun: true })), pk.analysisKey(pk.importRequest({ pkg: PKG, dryRun: false })));
  assert.notEqual(pk.analysisKey(create), pk.analysisKey(pk.importRequest({ pkg: PKG, includeTokens: true, dryRun: true })), 'changing an option invalidates the analysis');
  assert.deepEqual(pk.exportQuery(7, { tokens: true }), { page: 7, include_components: 1, include_template: 1, include_tokens: 1 });
  assert.equal(pk.packageFileName('kohevo studio/../x'), 'kohevo-studio-..-x.json');
  assert.equal(pk.packageFileName('a.json'), 'a.json');
});

test('packages: "Import into draft" is allowed only for a dry-run report that can commit, for exactly the analysed inputs', () => {
  const ok = { dry_run: true, can_commit: true };
  assert.equal(pk.canImport(ok, 'k', 'k'), true);
  assert.equal(pk.canImport(ok, 'k', 'other'), false, 'inputs changed since the analysis');
  assert.equal(pk.canImport({ dry_run: true, can_commit: false }, 'k', 'k'), false);
  assert.equal(pk.canImport({ dry_run: false, can_commit: true }, 'k', 'k'), false, 'only an analysis enables the commit');
  assert.equal(pk.canImport(null, 'k', 'k'), false);
  const report = { issues: [{ severity: 'error', code: 'route_collision' }, { severity: 'warning', code: 'unresolved_media' }] };
  assert.deepEqual(pk.splitIssues(report).errors.map((i) => i.code), ['route_collision']);
  assert.deepEqual(pk.splitIssues(report).warnings.map((i) => i.code), ['unresolved_media']);
  assert.equal(pk.reportOf({ ok: true, data: { report: { a: 1 } } }).a, 1);
  assert.equal(pk.reportOf({ ok: false, error: { code: 'validation_error', details: { report: { b: 2 } } } }).b, 2, 'a refused commit still carries its report');
  assert.equal(pk.reportOf({ ok: false, error: { code: 'csrf_error' } }), null);
});

test('packages: transport — export is a GET query, import a same-origin JSON POST carrying the CSRF header', async () => {
  const seen = [];
  const fetchImpl = async (url, init) => {
    seen.push({ url, init });
    return { status: 200, json: async () => ({ ok: true, data: { report: { dry_run: true } } }) };
  };
  const tr = createTransport({ apiUrl: 'http://site.test/plugins/studio-builder/admin/api.php', csrfToken: 'tok', fetchImpl });
  await tr.exportPackage(pk.exportQuery(3));
  await tr.importPackage(pk.importRequest({ pkg: PKG, dryRun: true }));
  assert.equal(seen[0].init.method, 'GET');
  assert.match(seen[0].url, /action=export_package&page=3&include_components=1&include_template=1&include_tokens=0/);
  assert.equal(seen[1].init.method, 'POST');
  assert.match(seen[1].url, /action=import_package$/);
  assert.equal(seen[1].init.headers['X-CSRF-Token'], 'tok');
  assert.equal(seen[1].init.headers['Content-Type'], 'application/json');
  assert.equal(seen[1].init.credentials, 'same-origin');
  assert.deepEqual(JSON.parse(seen[1].init.body).package, PKG);
});

function importingServer(initial) {
  const server = fakeServer(initial, (d, o) => ops.applyLocal(d, o, { manifest }));
  server.transport.importPackage = async (body) => {
    server.calls.push(['import_package', JSON.parse(JSON.stringify(body))]);
    if (body.expected_revision_id !== server.currentId) {
      return { ok: false, status: 409, error: { code: 'concurrency_conflict', message: '', details: { current_revision_id: server.currentId } } };
    }
    server.externalEdit((d) => { d.sections = [section([heading('Imported')], 'Imported')]; });
    const snap = await server.transport.document();
    return { ok: true, status: 201, data: { ...snap.data, deduplicated: false, report: { dry_run: false, committed: { pages: [{ mode: 'replaced' }] } } } };
  };
  return server;
}

test('packages: replace-draft goes through the engine command path — pending edits are saved first, the import carries expected_revision_id, and the editor adopts the imported draft', async () => {
  const sched = manualScheduler();
  const server = importingServer(doc([section([heading('Before')])]));
  const engine = new SyncEngine({ transport: server.transport, pageId: 1, schedule: sched.schedule, cancel: sched.cancel });
  engine.load((await server.transport.document()).data, manifest);
  engine.apply({ op: 'update_seo', payload: { seo: { title: 'Local edit' } } });
  const ok = await engine.command((base) => server.transport.importPackage({ ...pk.importRequest({ pkg: PKG, mode: pk.MODES.REPLACE, page: PAGE, revisionId: base.expected_revision_id, dryRun: false }), expected_revision_id: base.expected_revision_id }), { label: 'Imported' });
  assert.equal(ok, true);
  assert.deepEqual(server.calls.map((c) => c[0]), ['operations', 'import_package'], 'the local edit was saved before importing');
  assert.equal(server.calls[1][1].target_page_id, PAGE.id);
  assert.equal(engine.getSnapshot().status, STATUS.SAVED);
  assert.equal(engine.getSnapshot().working.sections[0].blocks[0].props.text, 'Imported', 'the imported draft is now the working document');
  assert.equal(engine.getSnapshot().undo.length > 0, true, 'one undo step back to the previous draft');
});

test('packages: a stale replace (409) enters the conflict state and keeps local edits; nothing further is sent', async () => {
  const sched = manualScheduler();
  const server = importingServer(doc([section([heading('Before')])]));
  const engine = new SyncEngine({ transport: server.transport, pageId: 1, schedule: sched.schedule, cancel: sched.cancel });
  engine.load((await server.transport.document()).data, manifest);
  server.externalEdit((d) => { d.seo.title = 'Someone else'; });
  const ok = await engine.command((base) => server.transport.importPackage({ ...pk.importRequest({ pkg: PKG, mode: pk.MODES.REPLACE, page: PAGE, revisionId: base.expected_revision_id, dryRun: false }), expected_revision_id: base.expected_revision_id }), {});
  assert.equal(ok, false);
  assert.equal(engine.getSnapshot().status, STATUS.CONFLICT);
  assert.equal(engine.getSnapshot().working.sections[0].blocks[0].props.text, 'Before', 'the editor still shows what the user had');
  assert.equal(await engine.command(() => Promise.resolve({ ok: true, status: 200, data: {} }), {}), false, 'no further command while in conflict');
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
  const dir = mkdtempSync(join(tmpdir(), 'sbx-pkg-'));
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

test('packages UI: the top bar offers Import / Export; the import tab has Analyse and a DISABLED "Import into draft" until an analysis allows it', { skip: skipReason || false }, () => {
  const shell = entry.renderPackages({ manifest, permissions: { view: true, edit: true }, withShell: true });
  assert.match(shell, /data-testid="open-packages"[^>]*aria-label="Import \/ Export"/);
  const html = entry.renderPackages({ manifest, permissions: { view: true, edit: true, tokens: false } });
  assert.match(html, /data-testid="package-import"/);
  assert.match(html, /<button[^>]*disabled=""[^>]*data-testid="import-analyze"/, 'nothing to analyse before a file is chosen');
  assert.match(html, /<button[^>]*disabled=""[^>]*data-testid="import-commit"[^>]*>Import into draft</, 'commit is disabled without a successful analysis');
  assert.match(html, /New draft page\(s\)/);
  assert.match(html, /Replace this page&#x27;s draft/);
  assert.ok(!/replace this site&#x27;s design tokens/i.test(html), 'the token option is hidden without studio-builder.tokens');
  assert.match(entry.renderPackages({ manifest, permissions: { view: true, edit: true, tokens: true } }), /Also replace this site&#x27;s design tokens/);
  const viewOnly = entry.renderPackages({ manifest, permissions: { view: true, edit: false } });
  assert.match(viewOnly, /data-testid="package-export"/, 'a viewer can only export');
  assert.ok(!/data-testid="package-import"/.test(viewOnly) && !/role="tab"[^>]*>Import</.test(viewOnly));
});

test('packages UI: the report shows readiness, errors, warnings, the plan and the draft-only component note', { skip: skipReason || false }, () => {
  const report = {
    dry_run: true, can_commit: false,
    summary: { pages_count: 1, global_components_count: 1, templates_count: 0, tokens_count: 0, sections_count: 2, blocks_count: 3, errors_count: 1, warnings_count: 1 },
    issues: [
      { severity: 'error', code: 'route_collision', item: 'page', path: '$.items[0].slug', message: 'A page with slug x exists.' },
      { severity: 'warning', code: 'unresolved_media', item: 'page', path: '$.items[0].document.seo.og_image_media_id', message: 'Image removed.' },
    ],
    planned_actions: [{ action: 'create_global_component', title: 'Promo' }, { action: 'create_page', slug: 'about' }],
  };
  const html = entry.renderPackages({ manifest, permissions: { view: true, edit: true }, report });
  assert.match(html, /data-can-commit="false"/);
  assert.match(html, /cannot be imported as it is/);
  assert.match(html, /data-code="route_collision"/);
  assert.match(html, /data-code="unresolved_media"/);
  assert.match(html, /Create global component “Promo” \(draft\)/);
  assert.match(html, /Create draft page \/about/);
  assert.match(html, /Imported global components are drafts/);
  const okHtml = entry.renderPackages({ manifest, permissions: { view: true, edit: true }, report: { ...report, can_commit: true, issues: [] } });
  assert.match(okHtml, /data-can-commit="true"/);
  assert.match(okHtml, /Ready to import\./);
});
