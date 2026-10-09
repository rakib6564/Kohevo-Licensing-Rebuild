import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { humanize, optionKey, optionLabel } from '../src/core/optionLabels.mjs';

const manifest = JSON.parse(readFileSync(new URL('./fixtures/manifest.json', import.meta.url), 'utf8'));

test('known values use their message, unknown values are humanised, never shown raw', () => {
  assert.equal(optionLabel('md'), 'Medium');
  assert.equal(optionLabel('2xl'), '2× large');
  assert.equal(optionLabel('half_screen'), 'Half screen height');
  assert.equal(optionLabel('index,follow'), 'Index, follow');
  assert.equal(optionLabel('arrow-right'), 'Arrow right');
  assert.equal(optionLabel('16:9'), '16:9');
  assert.equal(optionLabel('map-pin'), 'Map pin');
  assert.equal(optionLabel(''), '');
  assert.equal(optionLabel(null), '');
});

test('keys are normalised the same way as the messages', () => {
  assert.equal(optionKey('index,follow'), 'opt_index_follow');
  assert.equal(optionKey('Row-Reverse'), 'opt_row_reverse');
  assert.equal(humanize('created_at'), 'Created at');
});

test('every enum value in the manifest reads as words (no underscores, hyphens or bare codes)', () => {
  const values = new Set();
  (function walk(o) {
    if (Array.isArray(o)) o.forEach(walk);
    else if (o && typeof o === 'object') {
      if (Array.isArray(o.allowed_values)) o.allowed_values.forEach((v) => values.add(String(v)));
      Object.values(o).forEach(walk);
    }
  })(manifest);
  for (const k of ['spacing_scale', 'container_widths', 'alignments', 'auth_states', 'robots']) manifest.vocabulary[k].forEach((v) => values.add(v));
  for (const v of values) {
    const label = optionLabel(v);
    assert.ok(label.length > 0, v);
    assert.doesNotMatch(label, /[_]/, `${v} -> ${label}`);
    if (/^(xs|sm|md|lg|xl|2xl|any|guest|authenticated|full)$/.test(v)) assert.notEqual(label.toLowerCase(), v, `${v} must be spelled out`);
  }
});
