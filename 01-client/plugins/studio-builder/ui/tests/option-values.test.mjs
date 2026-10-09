import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { OPTION_VALUES, formatSize, sizedLabel, tableFor } from '../src/core/optionValues.mjs';

const manifest = JSON.parse(readFileSync(new URL('./fixtures/manifest.json', import.meta.url), 'utf8'));

test('sizes read in px and in words, not as rem codes', () => {
  assert.equal(formatSize('.25rem'), '4px');
  assert.equal(formatSize('1rem'), '16px');
  assert.equal(formatSize('1.25rem'), '20px');
  assert.equal(formatSize('0'), '0');
  assert.equal(formatSize('none'), 'no limit');
  assert.equal(formatSize('100vh'), 'full screen height');
  assert.equal(formatSize('50vh'), '50% of screen height');
  assert.equal(formatSize('95vw'), '95% of screen width');
});

test('the same size word shows its own size for each field', () => {
  assert.equal(sizedLabel('layout.flex.gap', 'md'), 'Medium · 16px');
  assert.equal(sizedLabel('layout.container.padding', 'md'), 'Medium · 32px');
  assert.equal(sizedLabel('core.card.padding', 'md'), 'Medium · 20px');
  assert.equal(sizedLabel('section.width', 'wide'), 'Wide · 1280px');
  assert.equal(sizedLabel('section.width', 'full'), 'Full width · no limit');
  assert.equal(sizedLabel('layout.section.min_height', 'screen'), 'Full screen height · full screen height');
  assert.equal(sizedLabel('core.text.size', 'base'), 'Base (normal) · 16px');
  assert.equal(sizedLabel('core.heading.level', 'h2'), 'Heading 2', 'a field with no size table keeps the plain name');
  assert.equal(sizedLabel('core.card.padding', 'unknown'), 'Unknown');
});

test('every field mapped to a table names a real field and covers each of its allowed values', () => {
  const fields = new Map();
  (function walk(o, owner) {
    if (Array.isArray(o)) o.forEach((x) => walk(x, owner));
    else if (o && typeof o === 'object') {
      if (o.type && Array.isArray(o.field_schema)) owner = o.type;
      if (Array.isArray(o.allowed_values) && o.key) fields.set(`${owner}.${o.key}`, o.allowed_values.map(String));
      Object.values(o).forEach((x) => walk(x, owner));
    }
  })(manifest, '');
  const vocab = { 'section.gap': manifest.vocabulary.spacing_scale, 'section.padding_y': manifest.vocabulary.spacing_scale, 'section.width': manifest.vocabulary.container_widths };
  for (const [fieldId, table] of Object.entries(OPTION_VALUES.fields)) {
    const allowed = fields.get(fieldId) || vocab[fieldId];
    assert.ok(allowed, `${fieldId} exists in the manifest`);
    const values = OPTION_VALUES.tables[table].values;
    for (const v of allowed.filter((x) => x !== 'auto')) assert.ok(Object.prototype.hasOwnProperty.call(values, v), `${fieldId}: ${v} has a size in ${table}`);
    assert.equal(tableFor(fieldId), table);
  }
});
