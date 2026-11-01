import { test } from 'node:test';
import assert from 'node:assert/strict';
import { groupPresets, PRESET_CATEGORIES } from '../src/core/library.mjs';

const p = (key, category, name, description = '') => ({ template_key: key, category, name, description });
const presets = [
  p('k-cta', 'cta', 'Call to Action', 'A closing banner'),
  p('k-faq', 'content', 'FAQ', 'Questions and answers'),
  p('k-hero2', 'hero', 'Hero — Centered'),
  p('k-hero1', 'hero', 'Hero — Classic'),
  p('k-x', 'unknown', 'Odd one'),
];

test('presets group by category in display order, keeping insertion order inside a group, unknown last', () => {
  const groups = groupPresets(presets, '', (c) => c);
  assert.deepEqual(groups.map((g) => g.category), ['hero', 'content', 'cta', 'unknown']);
  assert.deepEqual(groups[0].items.map((i) => i.template_key), ['k-hero2', 'k-hero1']);
  assert.ok(PRESET_CATEGORIES.indexOf('hero') < PRESET_CATEGORIES.indexOf('footer'));
});

test('search matches name, description and category label, case-insensitively', () => {
  assert.deepEqual(groupPresets(presets, 'classic', (c) => c).flatMap((g) => g.items.map((i) => i.template_key)), ['k-hero1']);
  assert.deepEqual(groupPresets(presets, 'BANNER', (c) => c).flatMap((g) => g.items.map((i) => i.template_key)), ['k-cta']);
  assert.deepEqual(groupPresets(presets, 'conte', (c) => c).flatMap((g) => g.items.map((i) => i.template_key)), ['k-faq']);
  assert.deepEqual(groupPresets(presets, 'zzz', (c) => c), []);
});

test('missing or malformed input is safe', () => {
  assert.deepEqual(groupPresets(null, '', (c) => c), []);
  assert.deepEqual(groupPresets(undefined, 'x', (c) => c), []);
});
