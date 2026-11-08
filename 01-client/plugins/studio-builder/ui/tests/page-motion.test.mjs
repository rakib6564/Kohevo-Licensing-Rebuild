// The page-level motion in `interactions` (page colours, adapt, progress bar, text effects, stagger, glow, magnet): the
// editor's normalisers produce only what ScrollEffects.php accepts, and each effect is kept when another changes.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  hasMotion, normalizeGlow, normalizeInteractions, normalizePageColors, normalizeProgress, normalizeStagger, normalizeTextEffect,
} from '../src/core/motion.mjs';

test('page colours: off with neither colour; blank colours dropped', () => {
  assert.equal(normalizePageColors(null), null);
  assert.equal(normalizePageColors({ background: ' ', text: '' }), null);
  assert.deepEqual(normalizePageColors({ background: ' #0b0b0c ', accent: '#fff' }), { background: '#0b0b0c' });
  assert.deepEqual(normalizePageColors({ text: 'text.primary' }), { text: 'text.primary' });
});

test('progress bar: needs an edge; height clamped to 1-12', () => {
  assert.equal(normalizeProgress({ color: '#000' }), null);
  assert.equal(normalizeProgress({ edge: 'left' }), null);
  assert.deepEqual(normalizeProgress({ edge: 'bottom', height: 40, color: '#ff5a1f' }), { edge: 'bottom', color: '#ff5a1f', height: 12 });
  assert.deepEqual(normalizeProgress({ edge: 'top', height: '' }), { edge: 'top' });
});

test('text effect: each effect keeps only its own setting, clamped', () => {
  assert.equal(normalizeTextEffect({ effect: 'typewriter' }), null);
  assert.deepEqual(normalizeTextEffect({ effect: 'light_words', dim: 1, step_ms: 50 }), { effect: 'light_words', dim: 5 });
  assert.deepEqual(normalizeTextEffect({ effect: 'lines', dim: 20, step_ms: 900 }), { effect: 'lines', step_ms: 400 });
});

test('stagger and glow: stagger needs a step, glow a colour', () => {
  assert.equal(normalizeStagger({}), null);
  assert.deepEqual(normalizeStagger({ step_ms: 1 }), { step_ms: 20 });
  assert.equal(normalizeGlow({ size: 200 }), null);
  assert.deepEqual(normalizeGlow({ color: 'color.accent', size: 5000 }), { color: 'color.accent', size: 800 });
});

test('interactions: the new effects survive a trigger change, the magnet joins the mouse levels, and they count as motion', () => {
  const now = {
    trigger: 'hover', page_colors: { background: '#000' }, adapt: true, progress: { edge: 'top' },
    text: { effect: 'lines' }, stagger: { step_ms: 80 }, glow: { color: '#fff' }, mouse: { magnet: 14 },
  };
  const out = normalizeInteractions({ ...now, trigger: '' });
  assert.deepEqual(out, {
    mouse: { magnet: 10 }, page_colors: { background: '#000' }, adapt: true, progress: { edge: 'top' },
    text: { effect: 'lines' }, stagger: { step_ms: 80 }, glow: { color: '#fff' },
  });
  assert.equal(normalizeInteractions({ adapt: 'yes' }).adapt, undefined, 'adapt is true or absent');
  for (const key of ['page_colors', 'progress', 'text', 'stagger', 'glow']) {
    assert.equal(hasMotion({ interactions: { [key]: now[key] } }), true, key);
  }
});
