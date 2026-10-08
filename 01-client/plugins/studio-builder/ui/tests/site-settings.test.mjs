import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { FONT_PRESETS, SITE_GROUPS, groupTokens, splitRef, tokenNameKey } from '../src/core/siteSettings.mjs';
import { t } from '../src/core/messages.mjs';

const manifest = JSON.parse(readFileSync(new URL('./fixtures/manifest.json', import.meta.url), 'utf8'));

test('tokens are grouped in display order and every token lands in a group', () => {
  const groups = groupTokens(manifest.tokens);
  assert.deepEqual(groups.map((g) => g.id), ['colors', 'fonts', 'shape', 'shadows', 'spacing']);
  assert.equal(groups.reduce((n, g) => n + g.tokens.length, 0), manifest.tokens.length);
  assert.ok(groups[0].tokens.every((tk) => ['surface', 'text', 'color', 'border'].includes(tk.category)));
});

test('an unknown category is kept, in a trailing group', () => {
  const groups = groupTokens([{ ref: 'z.one', category: 'z' }, { ref: 'font.body', category: 'font' }]);
  assert.deepEqual(groups.map((g) => g.id), ['fonts', 'other']);
  assert.deepEqual(groupTokens(null), []);
});

test('every manifest token has a friendly name and every group a title, in the UI messages', () => {
  for (const tk of manifest.tokens) assert.notEqual(t(tokenNameKey(tk.ref)), tokenNameKey(tk.ref), tk.ref);
  for (const g of SITE_GROUPS) assert.notEqual(t(g.titleKey), g.titleKey, g.id);
  for (const c of ['surface', 'text', 'color', 'border']) assert.notEqual(t(`ss_cat_${c}`), `ss_cat_${c}`, c);
});

test('suggested font stacks pass the server font pattern', () => {
  for (const f of FONT_PRESETS) assert.match(f, /^[A-Za-z0-9 ,'"-]{1,200}$/, f);
});

test('splitRef splits on the first dot', () => {
  assert.deepEqual(splitRef('surface.primary'), { category: 'surface', name: 'primary' });
  assert.deepEqual(splitRef('plain'), { category: '', name: 'plain' });
});
