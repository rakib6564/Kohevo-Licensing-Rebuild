import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { layoutOutline, WF_WIDTH } from '../src/core/wireframe.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const outlines = JSON.parse(readFileSync(join(here, 'fixtures', 'preset-outlines.json'), 'utf8'));
const KINDS = new Set(['bar', 'line', 'pill', 'box', 'card', 'dot']);

test('every preset lays out inside its box with known shape kinds and positive sizes', () => {
  const keys = Object.keys(outlines);
  assert.ok(keys.length >= 16);
  for (const key of keys) {
    const wf = layoutOutline(outlines[key]);
    assert.ok(wf.shapes.length > 0, `${key} draws something`);
    assert.ok(wf.height >= 24 && wf.height < 400, `${key} height ${wf.height}`);
    for (const s of wf.shapes) {
      assert.ok(KINDS.has(s.k), `${key}: kind ${s.k}`);
      assert.ok(s.w > 0 && s.h > 0, `${key}: positive size`);
      assert.ok(s.x >= -0.01 && s.x + s.w <= WF_WIDTH + 0.01, `${key}: ${s.k} x-range ${s.x}..${s.x + s.w}`);
      assert.ok(s.y >= -0.01 && s.y + s.h <= wf.height + 0.01, `${key}: ${s.k} y-range ${s.y}..${s.y + s.h} of ${wf.height}`);
    }
  }
});

test('layout is deterministic and does not mutate its input', () => {
  const input = JSON.stringify(outlines['system-section-pricing']);
  const a = layoutOutline(outlines['system-section-pricing']);
  const b = layoutOutline(JSON.parse(input));
  assert.deepEqual(a, b);
  assert.equal(JSON.stringify(outlines['system-section-pricing']), input);
});

test('different presets look different (no two share the same drawing)', () => {
  const seen = new Map();
  for (const key of Object.keys(outlines)) {
    const sig = JSON.stringify(layoutOutline(outlines[key]).shapes);
    assert.ok(!seen.has(sig), `${key} draws the same as ${seen.get(sig)}`);
    seen.set(sig, key);
  }
});

test('columns and item counts drive the drawing; hostile or empty input is safe', () => {
  const three = layoutOutline([{ bg: '', nodes: [{ t: 'layout.grid', c: 3, k: [{ t: 'core.heading', l: 'h3' }, { t: 'core.heading', l: 'h3' }, { t: 'core.heading', l: 'h3' }] }] }]);
  const bars = three.shapes.filter((s) => s.k === 'bar');
  assert.equal(bars.length, 3);
  assert.equal(new Set(bars.map((b) => Math.round(b.y))).size, 1, 'three columns share a row');
  assert.ok(new Set(bars.map((b) => Math.round(b.x))).size === 3, 'three distinct columns');
  for (const bad of [null, undefined, [], [{}], [{ nodes: 'x' }], [{ nodes: [null, { t: 'nope' }] }]]) {
    const wf = layoutOutline(bad);
    assert.ok(Number.isFinite(wf.height));
  }
  let deep = { t: 'layout.container', k: [] };
  const root = deep;
  for (let i = 0; i < 40; i++) { const next = { t: 'layout.container', k: [] }; deep.k.push(next); deep = next; }
  assert.ok(Number.isFinite(layoutOutline([{ bg: '', nodes: [root] }]).height), 'depth is capped');
});
