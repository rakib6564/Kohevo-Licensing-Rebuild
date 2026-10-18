// CL-02: a container prop that "Flex & grid" (style.layout) also sets is shown read-only while the style owns it,
// because the style's scoped rule comes after the prop's class and wins.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { LAYOUT_OWNED_PROPS, layoutOwnedProps } from '../src/core/propPresenters.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const manifest = JSON.parse(readFileSync(new URL('./fixtures/manifest.json', import.meta.url), 'utf8'));

function fieldSchemas() {
  const out = new Map();
  (function walk(o) {
    if (Array.isArray(o)) o.forEach(walk);
    else if (o && typeof o === 'object') {
      if (typeof o.type === 'string' && Array.isArray(o.field_schema)) out.set(o.type, o.field_schema);
      Object.values(o).forEach(walk);
    }
  })(manifest);
  return out;
}

test('every owned prop is a real field of its block', () => {
  const schemas = fieldSchemas();
  for (const [type, map] of Object.entries(LAYOUT_OWNED_PROPS)) {
    const fields = schemas.get(type);
    assert.ok(fields, `${type} is in the manifest`);
    for (const key of Object.keys(map)) assert.ok(fields.find((f) => f.key === key), `${type}.${key} exists`);
  }
});

test('a prop is owned only while the matching style.layout key holds a value', () => {
  assert.deepEqual(layoutOwnedProps('layout.flex', { layout: { direction: 'column' } }), ['direction']);
  assert.deepEqual(layoutOwnedProps('layout.flex', { layout: { direction: 'column', gap: '24px', wrap: 'wrap' } }), ['direction', 'wrap', 'gap']);
  assert.deepEqual(layoutOwnedProps('layout.grid', { layout: { columns: 4, align: 'center' } }), ['columns', 'align']);
  assert.deepEqual(layoutOwnedProps('core.container', { layout: { gap: '1rem' } }), ['gap']);
  assert.deepEqual(layoutOwnedProps('core.container', { layout: { wrap: 'nowrap' } }), ['wrap'], 'a Flex & grid wrap value owns the container\'s Wrap prop');
  assert.deepEqual(layoutOwnedProps('layout.flex', { layout: { direction: '' } }), [], 'a cleared value hands the prop back');
  assert.deepEqual(layoutOwnedProps('layout.flex', { layout: { order: 2 } }), [], 'an unrelated layout key owns nothing');
  assert.deepEqual(layoutOwnedProps('layout.flex', {}), []);
  assert.deepEqual(layoutOwnedProps('core.heading', { layout: { direction: 'column' } }), [], 'only containers have these props');
});

let entry = null;
let skipReason = null;
try {
  const esbuild = await import('esbuild');
  const dir = mkdtempSync(join(tmpdir(), 'sbx-owned-'));
  const outfile = join(dir, 'entry.mjs');
  await esbuild.build({
    stdin: {
      contents: "export { ObjectFields } from '../../src/components/fields/FieldControl.jsx'; export { renderToStaticMarkup } from 'react-dom/server'; export { createElement } from 'react';",
      resolveDir: join(here, 'fixtures'), loader: 'js',
    },
    outfile, bundle: true, platform: 'node', format: 'esm', jsx: 'automatic',
    conditions: ['production'], define: { 'process.env.NODE_ENV': '"production"' }, logLevel: 'silent',
    banner: { js: "import { createRequire as __sbxCreateRequire } from 'node:module'; const require = __sbxCreateRequire(import.meta.url);" },
  });
  entry = await import(pathToFileURL(outfile).href);
  process.on('exit', () => rmSync(dir, { recursive: true, force: true }));
} catch (e) {
  if (!String(e && e.message).includes("Cannot find package 'esbuild'")) throw e;
  skipReason = 'dev dependencies not installed (run npm ci in plugins/studio-builder/ui)';
}

test('the Inspector draws an owned prop disabled, with a note, and leaves the others editable', { skip: skipReason || false }, () => {
  const { ObjectFields, renderToStaticMarkup, createElement } = entry;
  const schema = fieldSchemas().get('layout.flex');
  const html = renderToStaticMarkup(createElement('div', null, createElement(ObjectFields, {
    schema, value: { direction: 'row' }, manifest, onChange: () => {}, blockType: 'layout.flex',
    owned: layoutOwnedProps('layout.flex', { layout: { direction: 'column' } }),
  })));
  assert.match(html, /<fieldset class="sbx-owned" disabled="" data-owned="direction">/);
  assert.match(html, /Set in Flex &amp; grid below/);
  assert.equal((html.match(/class="sbx-owned"/g) || []).length, 1, 'only the owned prop is read-only');
});
