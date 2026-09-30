// The React shell rendered for real (react-dom/server): regions, selection,
// metadata-driven property panels, responsive state, save/conflict states.
// Needs the dev dependencies (npm ci); skipped — loudly — without them.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { manifest, doc, section, heading, block, container } from './helpers.mjs';
import * as ops from '../src/core/operations.mjs';

const here = dirname(fileURLToPath(import.meta.url));
let entry = null;
let skipReason = null;
let esbuild = null;
try {
  esbuild = await import('esbuild');
} catch {
  skipReason = 'dev dependencies not installed (run npm ci in plugins/studio-builder/ui)';
}
if (esbuild) {
  // A build/import failure here is a real failure, never a skip.
  const dir = mkdtempSync(join(tmpdir(), 'sbx-render-'));
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

const h = heading('Welcome to Alpha');
const feature = block('core.feature_list', { title: 'Why us', columns: 3, items: [{ heading: 'Fast', body: '', url: null }], card_options: { bordered: true, surface_token: null } });
const hero = block('core.hero', { eyebrow: '', heading: 'Hi', subheading: '', primary_cta: null, media: null, accent_token: null });
const pageDoc = doc([section([h, feature, container([hero])], 'Intro'), section([], 'Footer area')]);

test('shell loads: top bar, structure tree, sandboxed server canvas, property panel', { skip: skipReason || false }, () => {
  const html = entry.render({ manifest, document: pageDoc, revisionId: 42 });
  assert.match(html, /role="banner"/);
  assert.match(html, /role="tree"/);
  assert.match(html, /aria-label="Properties"/);
  assert.match(html, /<iframe[^>]*sandbox="allow-same-origin"/, 'canvas iframe is sandboxed without scripts');
  assert.ok(!/<iframe[^>]*sandbox="[^"]*allow-scripts/.test(html), 'canvas never gets allow-scripts');
  assert.match(html, /canvas\.php\?page=1&amp;v=42/, 'canvas is the server renderForEditor() output for the current revision');
  assert.match(html, /About us/);
  assert.equal((html.match(/role="treeitem"/g) || []).length, 6, 'every section and block appears in the structure');
  assert.match(html, /Welcome to Alpha/);
  assert.match(html, /Page settings/, 'with nothing selected the page settings are shown');
});

test('selection highlights the node and generates the property panel from the manifest', { skip: skipReason || false }, () => {
  const html = entry.render({ manifest, document: pageDoc, selection: feature.id });
  assert.match(html, new RegExp(`data-row="${feature.id}"[^>]*aria-selected="true"`));
  assert.match(html, /<h2 class="sbx-inspector__title">Feature List<\/h2>/);
  assert.match(html, /Features \(1\/12\)/, 'repeater control with max_items from the schema');
  assert.match(html, /<legend>Card Options<\/legend>/, 'object control');
  assert.match(html, /value="Why us"/, 'string control bound to props');
  assert.match(html, /type="number"[^>]*min="1"[^>]*max="4"/, 'number constraints from the schema');

  const heroHtml = entry.render({ manifest, document: pageDoc, selection: hero.id });
  assert.match(heroHtml, /Primary Call to Action/, 'link control');
  assert.match(heroHtml, /Hero Image/, 'media control');
  assert.match(heroHtml, /<optgroup label="color">/, 'token control fed by the manifest tokens');
  assert.match(heroHtml, /Move out of container/, 'keyboard alternative to drag & drop');
});

test('section selection shows responsive layout controls in the canonical breakpoints', { skip: skipReason || false }, () => {
  const html = entry.render({ manifest, document: pageDoc, selection: pageDoc.sections[0].id, viewportKey: 'tablet' });
  assert.match(html, /<legend>Columns<\/legend>/);
  for (const bp of ['base', 'sm', 'md', 'lg']) assert.ok(html.includes(`>${bp}`), `breakpoint ${bp}`);
  assert.match(html, />md ●</, 'the active viewport breakpoint is marked');
  assert.match(html, /width:820px/, 'tablet viewport = 820px frame (md)');
  assert.match(html, /aria-pressed="true"[^>]*>Tablet</);
});

test('save state and conflict state are visible and blocking', { skip: skipReason || false }, () => {
  const dirty = entry.render({ manifest, document: pageDoc, mutate: (e) => e.apply(ops.updateBlockProps(h.id, { level: 'h2', text: 'x' })) });
  assert.match(dirty, /data-status="dirty"/);
  assert.match(dirty, /Unsaved changes/);
  assert.match(dirty, /canvas updates after save/);

  const conflicted = entry.render({
    manifest, document: pageDoc,
    mutate: (e) => e.set({ status: 'conflict', conflict: { currentRevisionId: 50, expectedRevisionId: 42, localChangeCount: 2 }, pending: [{}, {}] }),
  });
  assert.match(conflicted, /data-testid="conflict-banner"/);
  assert.match(conflicted, /role="alert"/);
  assert.match(conflicted, /2 most recent change/);
  assert.match(conflicted, /server revision 50, yours 42/);
  assert.match(conflicted, /Reload latest version/);
  assert.match(conflicted, /<button[^>]*disabled=""[^>]*>Publish<\/button>/, 'publishing is blocked during a conflict');

  const shared = entry.render({ manifest, document: pageDoc, lockState: { held: false, otherEditor: true } });
  assert.match(shared, /Someone else has this page open/);
});

test('an empty page offers "Add section" and nothing else to edit', { skip: skipReason || false }, () => {
  const html = entry.render({ manifest, document: doc([]) });
  assert.match(html, /This page is empty/);
  assert.match(html, /Add section/);
});
