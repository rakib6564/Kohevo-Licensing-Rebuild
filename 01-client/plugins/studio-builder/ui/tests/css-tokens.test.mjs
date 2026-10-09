import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const css = readFileSync(new URL('../src/builder.css', import.meta.url), 'utf8');
const rootStart = css.indexOf(':root {');
const rootEnd = css.indexOf('\n}\n', rootStart) + 3;
const tokenBlock = css.slice(rootStart, rootEnd);
const rest = css.slice(0, rootStart) + css.slice(rootEnd);
// The wireframe thumbnails are miniature pages with their own light palette; they are not builder chrome.
const chrome = rest.split('\n').filter((l) => !l.trimStart().startsWith('.sbx-wf'));

const count = (re) => chrome.reduce((n, l) => n + (l.match(re) || []).length, 0);

// Ratchet: the numbers only go down. When you replace a literal with a token, lower the ceiling.
const HEX_CEILING = 119;
const RGBA_CEILING = 124;

// Set at runtime by the mobile shell (visual viewport / sheet height), so they have no static definition.
const RUNTIME = new Set(['--sbx-sheet-h', '--sbx-kb-inset']);

test('every token the CSS uses is defined in the :root block', () => {
  const defined = new Set([...tokenBlock.matchAll(/(--sbx-[a-z0-9-]+):/g)].map((m) => m[1]));
  const inline = new Set([...rest.matchAll(/(--sbx-[a-z0-9-]+):/g)].map((m) => m[1]));
  const used = new Set([...css.matchAll(/var\((--sbx-[a-z0-9-]+)/g)].map((m) => m[1]));
  const missing = [...used].filter((n) => !defined.has(n) && !inline.has(n) && !RUNTIME.has(n));
  assert.deepEqual(missing, []);
});

test('the token scales are complete', () => {
  for (const n of ['fs-2xs', 'fs-xs', 'fs-sm', 'fs-md', 'fs-lg', 'r-xs', 'r-sm', 'r-md', 'r-lg', 'r-xl', 'r-pill', 's-1', 's-2', 's-3', 's-4', 's-5', 'fill-1', 'fill-2', 'fill-3', 'trans']) {
    assert.ok(tokenBlock.includes(`--sbx-${n}:`), `--sbx-${n} is defined`);
  }
});

test('hard-coded hex colours outside the token block do not grow', () => {
  assert.ok(count(/#[0-9a-fA-F]{3,8}\b/g) <= HEX_CEILING, `hex literals: ${count(/#[0-9a-fA-F]{3,8}\b/g)} (ceiling ${HEX_CEILING})`);
});

test('hard-coded rgb()/rgba() colours outside the token block do not grow', () => {
  assert.ok(count(/rgba?\(/g) <= RGBA_CEILING, `rgba literals: ${count(/rgba?\(/g)} (ceiling ${RGBA_CEILING})`);
});

test('common type sizes and radii use the scale, not literals', () => {
  assert.equal(count(/font-size: (10|11|12|13|14)px/g), 0);
  assert.equal(count(/border-radius: (3|4|6|8|10|999)px(?=[;\s}])/g), 0);
});
