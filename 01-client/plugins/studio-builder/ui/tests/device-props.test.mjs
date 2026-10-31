// Per-device prop values (DeviceProps.php): read and write `responsive.<device>.props` without touching anything else,
// and offer each field's choices.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { deviceChoices, hasDeviceProp, readDeviceProp, writeDeviceProp } from '../src/core/deviceProps.mjs';
import { isStructuralChange } from '../src/core/canvasLiveSync.mjs';
import { block, doc, topContainer } from './helpers.mjs';

test('write keeps the device style and removes what is left empty', () => {
  const start = { mobile: { style: { padding: { top: '4px' } } } };
  const set = writeDeviceProp(start, 'mobile', 'columns', 1);
  assert.deepEqual(set, { mobile: { style: { padding: { top: '4px' } }, props: { columns: 1 } } });
  assert.deepEqual(start, { mobile: { style: { padding: { top: '4px' } } } }, 'never mutates');
  assert.equal(readDeviceProp(set, 'mobile', 'columns'), 1);
  assert.equal(readDeviceProp(set, 'tablet', 'columns'), undefined);
  assert.equal(readDeviceProp(set, 'desktop', 'columns'), undefined, 'desktop is the prop itself');
  assert.deepEqual(writeDeviceProp(set, 'mobile', 'columns', undefined), start);
  assert.deepEqual(writeDeviceProp({ tablet: { props: { 'options.per_view': 2 } } }, 'tablet', 'options.per_view', ''), {});
  assert.equal(hasDeviceProp(set, 'columns'), true);
});

test('choices: an enum offers its values, a number every whole number in range', () => {
  assert.deepEqual(deviceChoices({ type: 'enum', allowed_values: ['2', '3', '4', '6'] }), ['2', '3', '4', '6']);
  assert.deepEqual(deviceChoices({ type: 'number', min: 1, max: 4 }), [1, 2, 3, 4]);
  assert.deepEqual(deviceChoices({ type: 'number', min: 1, max: 12 }).length, 12);
});

test('the manifest marks the column-like fields per device', () => {
  const manifest = JSON.parse(readFileSync(new URL('./fixtures/manifest.json', import.meta.url), 'utf8'));
  const marked = [];
  const walk = (type, fields, prefix = '') => fields.forEach((f) => {
    if (f.per_device) marked.push(`${type}:${prefix}${f.key}`);
    if (f.properties) walk(type, f.properties, `${prefix}${f.key}.`);
  });
  manifest.blocks.forEach((b) => walk(b.type, b.field_schema));
  for (const want of ['core.gallery:columns', 'core.query_loop:columns', 'layout.grid:columns', 'core.feature_list:columns', 'core.carousel:options.per_view', 'core.text:columns', 'core.rich_text:columns']) {
    assert.ok(marked.includes(want), want);
  }
});

test('a per-device value repaints the block from the server (the markup carries it)', () => {
  const g = block('core.gallery', { columns: '6' });
  const before = doc([topContainer([g])]);
  const after = doc([topContainer([{ ...g, responsive: { mobile: { props: { columns: '2' } } } }])]);
  assert.equal(isStructuralChange(before, after), true);
});
