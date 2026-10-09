import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { categoryHeading, groupForPicker, sampleStyle, tokenLabel } from '../src/core/tokenPicker.mjs';

const manifest = JSON.parse(readFileSync(new URL('./fixtures/manifest.json', import.meta.url), 'utf8'));

test('every manifest token has a friendly name, never its ref', () => {
  for (const tk of manifest.tokens) {
    const label = tokenLabel(tk.ref);
    assert.notEqual(label, tk.ref);
    assert.doesNotMatch(label, /\./, tk.ref);
  }
  assert.equal(tokenLabel('surface.primary'), 'Primary');
  assert.equal(tokenLabel('vendor.brand_blue'), 'Brand blue');
});

test('tokens group under translated headings, in first-seen order', () => {
  const surface = manifest.tokens.filter((tk) => ['surface', 'color'].includes(tk.category));
  const groups = groupForPicker(surface);
  assert.deepEqual(groups.map((g) => g.heading), ['Accent', 'Surfaces'].sort((a, b) => groups.map((g) => g.heading).indexOf(a) - groups.map((g) => g.heading).indexOf(b)));
  assert.ok(groups.every((g) => g.items.length > 0));
  assert.equal(categoryHeading('radius'), 'Corner radius');
  assert.equal(categoryHeading('odd_kind'), 'Odd kind');
});

test('a sample shows the token the way it looks', () => {
  assert.deepEqual(sampleStyle('surface', '#fff'), { background: '#fff' });
  assert.equal(sampleStyle('radius', '8px').borderRadius, '8px');
  assert.equal(sampleStyle('shadow', 'none').boxShadow, 'none');
  assert.equal(sampleStyle('font', 'Georgia, serif').fontFamily, 'Georgia, serif');
  assert.equal(sampleStyle('space', '1rem 2rem').width, '1rem');
  assert.deepEqual(sampleStyle('unknown', 'x'), {});
});
