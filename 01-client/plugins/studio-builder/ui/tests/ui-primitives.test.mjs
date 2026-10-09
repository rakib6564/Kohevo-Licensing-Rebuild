// The shared UI primitives rendered for real (react-dom/server): the markup the stylesheet and e2e specs rely on.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
let ui = null;
let React = null;
let renderToStaticMarkup = null;
let skip = false;
try {
  const esbuild = await import('esbuild');
  const dir = mkdtempSync(join(tmpdir(), 'sbx-ui-'));
  const outfile = join(dir, 'ui.mjs');
  // A build failure is a real failure, never a skip: only a missing esbuild skips.
  await esbuild.build({
    entryPoints: [join(here, 'fixtures', 'ui-entry.jsx')],
    outfile, bundle: true, platform: 'node', format: 'esm', jsx: 'automatic',
    conditions: ['production'], define: { 'process.env.NODE_ENV': '"production"' }, logLevel: 'silent',
    banner: { js: "import { createRequire as __sbxCreateRequire } from 'node:module'; const require = __sbxCreateRequire(import.meta.url);" },
  });
  ui = await import(pathToFileURL(outfile).href);
  ({ React, renderToStaticMarkup } = ui);
  process.on('exit', () => rmSync(dir, { recursive: true, force: true }));
} catch (e) {
  if (e && e.code !== 'ERR_MODULE_NOT_FOUND') throw e;
  skip = 'dev dependencies not installed (run npm ci in plugins/studio-builder/ui)';
}

const opts = [{ value: 'a', label: 'Alpha' }, { value: 'b', label: 'Beta', hasValue: true }];

test('Pills: a group of pressed buttons, the active one marked', { skip }, () => {
  const html = renderToStaticMarkup(React.createElement(ui.Pills, { label: 'Pick', options: opts, value: 'a', onChange() {} }));
  assert.match(html, /role="group"/);
  assert.match(html, /aria-label="Pick"/);
  assert.match(html, /class="sbx-segmented-pill is-active"[^>]*aria-pressed="true"/);
  assert.match(html, /class="sbx-segmented-pill has-value"[^>]*aria-pressed="false"/);
});

test('Pills: a tablist uses tab roles and aria-selected', { skip }, () => {
  const html = renderToStaticMarkup(React.createElement(ui.Pills, { role: 'tablist', label: 'States', options: opts, value: 'b', onChange() {} }));
  assert.match(html, /role="tablist"/);
  assert.match(html, /role="tab"[^>]*aria-selected="true"/);
  assert.ok(!html.includes('aria-pressed'));
});

test('Pills: extra option props and a class reach the DOM', { skip }, () => {
  const html = renderToStaticMarkup(React.createElement(ui.Pills, { className: 'x', label: 'L', value: 'a', onChange() {}, options: [{ value: 'a', label: 'A', 'data-settings-view': 'page' }] }));
  assert.match(html, /sbx-segmented-pills x/);
  assert.match(html, /data-settings-view="page"/);
});

test('Field: a label for a control, or plain text for a group, plus hint and error', { skip }, () => {
  const withFor = renderToStaticMarkup(React.createElement(ui.Field, { label: 'Name', htmlFor: 'n', hint: 'Shown on the card', error: 'Required' }, React.createElement('input', { id: 'n' })));
  assert.match(withFor, /<label class="sbx-field__label" for="n">Name<\/label>/);
  assert.match(withFor, /<p class="sbx-hint">Shown on the card<\/p>/);
  assert.match(withFor, /<p class="sbx-field__error" role="alert">Required<\/p>/);
  const group = renderToStaticMarkup(React.createElement(ui.Field, { label: 'Align', variant: 'choice' }, 'x'));
  assert.match(group, /class="sbx-field sbx-field--choice"/);
  assert.match(group, /<span class="sbx-field__label">Align<\/span>/);
});

test('Tile: icon over label; a disabled tile shows its reason and is described by it', { skip }, () => {
  const html = renderToStaticMarkup(React.createElement(ui.Tile, { icon: 'I', label: 'Heading', srText: 'A title', disabled: true, reason: 'Section is full' }));
  assert.match(html, /class="sbx-tile is-disabled"/);
  assert.match(html, /aria-disabled="true"/);
  assert.match(html, /sbx-tile__label">Heading</);
  assert.match(html, /sbx-sr-only sbx-tile__desc">A title</);
  const id = html.match(/aria-describedby="([^"]+)"/)[1];
  assert.ok(html.includes(`id="${id}"`));
  assert.match(html, /Section is full/);
});

test('Tile: an enabled tile has no disabled state or reason', { skip }, () => {
  const html = renderToStaticMarkup(React.createElement(ui.Tile, { label: 'Text' }));
  assert.ok(!html.includes('aria-disabled'));
  assert.ok(!html.includes('sbx-tile__reason'));
});
