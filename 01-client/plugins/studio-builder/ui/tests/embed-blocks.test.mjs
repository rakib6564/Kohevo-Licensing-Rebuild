// The Forms and Booking embed blocks in the builder: how they are added (which slot asks for what) and the
// dropdown a pick-list provider parameter becomes in the Inspector.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { manifest } from './helpers.mjs';
import * as d from '../src/core/doc.mjs';
import * as fields from '../src/core/fields.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const def = (type) => d.blockDefinition(manifest, type);
const provider = (key) => manifest.providers.find((p) => p.key === key);

let entry = null;
let skipReason = null;
try {
  const esbuild = await import('esbuild');
  const dir = mkdtempSync(join(tmpdir(), 'sbx-embed-'));
  const outfile = join(dir, 'entry.mjs');
  await esbuild.build({
    stdin: {
      contents: "export { FieldControl } from '../../src/components/fields/FieldControl.jsx'; export { renderToStaticMarkup } from 'react-dom/server'; export { createElement } from 'react';",
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

test('forms.embed: a business component bound to forms.form_embed, which needs the form picked on insert', () => {
  const b = def('forms.embed');
  assert.equal(b.category, 'business');
  assert.equal(b.required_entitlement, 'forms');
  assert.deepEqual(b.binding_slots, [{ provider: 'forms.form_embed', slot: 'form' }]);
  const { bindings, needsParams } = fields.defaultBindings(b, manifest);
  assert.deepEqual(bindings, {});
  assert.equal(needsParams[0].slot, 'form');
  assert.deepEqual(needsParams[0].params.map((p) => [p.key, p.required]), [['id', true]]);
});

test('booking.embed: binds the whole booking page by default; a service is an optional pick', () => {
  const b = def('booking.embed');
  assert.equal(b.required_entitlement, 'booking');
  assert.deepEqual(b.binding_slots, [{ provider: 'booking.embed_target', slot: 'service' }]);
  const { bindings, needsParams } = fields.defaultBindings(b, manifest);
  assert.deepEqual(bindings, { service: { provider: 'booking.embed_target' } });
  assert.deepEqual(needsParams, []);
  assert.equal(b.default_props.min_height, 560);
});

test('both pick-list parameters ship their choices (a list, even when empty)', () => {
  for (const key of ['forms.form_embed', 'booking.embed_target']) {
    const id = provider(key).params.find((p) => p.key === 'id');
    assert.equal(id.type, 'number');
    assert.ok(Array.isArray(id.choices), `${key}.id carries choices`);
  }
});

test('a choices parameter is a dropdown of what exists, not a number box', { skip: skipReason || false }, () => {
  const field = { key: 'id', type: 'number', label: 'Form', required: true, integer_only: true, min: 1, choices: [{ value: 4, label: 'Contact us' }, { value: 9, label: 'Quote <b>' }] };
  const html = (f, value) => entry.renderToStaticMarkup(entry.createElement(entry.FieldControl, { field: f, value, onChange: () => {}, manifest }));

  const picked = html(field, 9);
  assert.match(picked, /<select/);
  assert.doesNotMatch(picked, /type="number"/);
  assert.match(picked, /<option value="4">Contact us<\/option>/);
  assert.match(picked, /<option value="9" selected="">Quote &lt;b&gt;<\/option>/, 'labels are text, never markup');
  assert.match(picked, /<option value=""[^>]*>Choose…<\/option>/, 'a required pick starts on a prompt');

  assert.match(html({ ...field, required: false }, null), /<option value=""[^>]*>None<\/option>/, 'an optional pick can be left empty');
  assert.match(html(field, 77), /<option value="77" selected="">#77<\/option>/, 'a form deleted since stays visible instead of silently changing');
  const none = html({ ...field, choices: [] }, null);
  assert.match(none, /disabled/);
  assert.match(none, /Nothing to choose from yet/);
  assert.match(html({ key: 'id', type: 'number', label: 'N', required: false }, 3), /type="number"/, 'no choices: still a number box');
});

test('clearing an optional pick leaves the parameter out (binding params are never null)', () => {
  const src = readFileSync(join(here, '..', 'src', 'components', 'inspectors', 'BlockInspector.jsx'), 'utf8');
  assert.match(src, /params: withoutEmpty\(params\)/);
});
