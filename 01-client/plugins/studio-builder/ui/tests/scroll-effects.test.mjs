// Sticky and parallax in `interactions`: the editor's normalisers produce only what ScrollEffects.php accepts,
// and changing the trigger keeps them (and the reverse).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { hasMotion, normalizeInteractions, normalizeParallax, normalizeSticky } from '../src/core/motion.mjs';

test('sticky: off without an edge; offset clamped, every-device lists and false flags dropped', () => {
  assert.equal(normalizeSticky(null), null);
  assert.equal(normalizeSticky({ offset: 10 }), null);
  assert.equal(normalizeSticky({ edge: 'left' }), null);
  assert.deepEqual(normalizeSticky({ edge: 'top', offset: 900, devices: ['mobile', 'desktop', 'watch'], shadow: false, background: ' #fff ' }),
    { edge: 'top', offset: 400, devices: ['desktop', 'mobile'], background: '#fff' });
  assert.deepEqual(normalizeSticky({ edge: 'top', offset: 0, devices: ['desktop', 'tablet', 'mobile'] }), { edge: 'top' });
  assert.deepEqual(normalizeSticky({ edge: 'bottom', hide_on_scroll: true, shadow: true }), { edge: 'bottom', shadow: true }, 'hiding is for a top edge only');
});

test('parallax: needs a speed; layer defaults to background; speed clamped', () => {
  assert.equal(normalizeParallax({ layer: 'element' }), null);
  assert.deepEqual(normalizeParallax({ speed: 2 }), { layer: 'background', speed: 5 });
  assert.deepEqual(normalizeParallax({ speed: '33.4', layer: 'element', direction: 'down', devices: ['tablet'] }),
    { layer: 'element', speed: 33, direction: 'down', devices: ['tablet'] });
  assert.deepEqual(normalizeParallax({ speed: 30, direction: 'up' }), { layer: 'background', speed: 30 });
});

test('interactions: a trigger change keeps sticky and parallax, and turning them off keeps the trigger', () => {
  const now = { trigger: 'hover', sticky: { edge: 'top' }, parallax: { speed: 20 } };
  assert.deepEqual(normalizeInteractions({ ...now, trigger: '' }), { sticky: { edge: 'top' }, parallax: { layer: 'background', speed: 20 } });
  assert.deepEqual(normalizeInteractions({ ...now, sticky: null, parallax: null }), { trigger: 'hover' });
  assert.equal(hasMotion({ interactions: { sticky: { edge: 'top' } } }), true);
});
