import { test } from 'node:test';
import assert from 'node:assert/strict';
import { colourFieldProps, cssToHex, effectiveColour, partField } from '../src/core/effectiveColour.mjs';

const tokens = [
  { ref: 'surface.accent', category: 'surface', value: '#e9008a' },
  { ref: 'text.inverse', category: 'text', value: '#ffffff' },
  { ref: 'color.accent', category: 'color', value: '#e9008a' },
];
// The contact page's button: every colour is in its `button` part, the hover in `button:hover`.
const button = {
  type: 'core.button',
  props: { variant: 'primary' },
  style: {},
  style_states: null,
  style_parts: {
    button: { color: '#0c0905', background: { color: '#ffffff' }, border: { color: '#eadde4', radius: '999px' }, typography: { size: '15px' } },
    'button:hover': { color: '#e9008a', border: { color: '#e9008a' } },
  },
};
const ctx = { tokens, computed: null, partLabel: 'Button' };

test('a button styled through its part shows the part colours in Normal', () => {
  assert.deepEqual(['text', 'background', 'border'].map((f) => effectiveColour(button, f, 'normal', ctx).css), ['#0c0905', '#ffffff', '#eadde4']);
  assert.equal(effectiveColour(button, 'text', 'normal', ctx).source, 'part');
});

test('Hover shows the hover part, and Normal for what it does not change', () => {
  const text = effectiveColour(button, 'text', 'hover', ctx);
  assert.deepEqual([text.css, text.source], ['#e9008a', 'part']);
  const bg = effectiveColour(button, 'background', 'hover', ctx);
  assert.deepEqual([bg.css, bg.source], ['#ffffff', 'normal']);
});

test('a plain button shows its variant by theme colour, then the block value wins', () => {
  const plain = { type: 'core.button', props: { variant: 'primary' }, style: {} };
  const bg = effectiveColour(plain, 'background', 'normal', ctx);
  assert.deepEqual([bg.css, bg.token, bg.source, bg.detail], ['#e9008a', 'surface.accent', 'variant', 'primary']);
  assert.equal(effectiveColour({ ...plain, props: { variant: 'ghost' } }, 'background', 'normal', ctx), null);
  const own = { ...plain, style: { surface_token: 'color.accent' } };
  assert.equal(effectiveColour(own, 'background', 'normal', ctx).source, 'block');
  assert.equal(effectiveColour({ ...plain, style: { typography: { color: '#111111' } } }, 'text', 'normal', ctx).css, '#111111');
});

test('another block falls back on the canvas, named by the theme colour it matches', () => {
  const block = { type: 'core.text', props: {}, style: {} };
  const seen = effectiveColour(block, 'text', 'normal', { tokens, computed: { color: 'rgb(233, 0, 138)', backgroundColor: 'rgba(0, 0, 0, 0)' } });
  assert.deepEqual([seen.css, seen.token, seen.source], ['#e9008a', 'surface.accent', 'stylesheet']);
  assert.equal(effectiveColour(block, 'background', 'normal', { tokens, computed: { backgroundColor: 'rgba(0, 0, 0, 0)' } }), null);
  assert.equal(cssToHex('rgb(0, 15, 255)'), '#000fff');
  assert.equal(cssToHex('rgba(0, 0, 0, 0.5)'), 'rgba(0, 0, 0, 0.5)');
});

test('a state on the block is ignored where a part beats it, and shown where nothing does', () => {
  const shadowed = { ...button, style_parts: { button: { color: '#000000' } }, style_states: { hover: { color: '#ff0000' } } };
  assert.equal(effectiveColour(shadowed, 'text', 'hover', ctx).css, '#000000');
  const free = { type: 'core.text', style: {}, style_states: { hover: { color: '#ff0000' } } };
  assert.equal(effectiveColour(free, 'text', 'hover', ctx).source, 'state');
});

test('an edit goes where it will show: the part that holds the colour', () => {
  let saved = null;
  const props = colourFieldProps(button, 'background', 'hover', ctx, (next) => { saved = next; });
  assert.equal(props.value, undefined);
  props.onToken('surface.accent');
  assert.equal(saved['button:hover'].background.color, 'surface.accent');
  assert.equal(saved.button.background.color, '#ffffff', 'Normal is untouched');

  const normal = colourFieldProps(button, 'text', 'normal', ctx, (next) => { saved = next; });
  assert.equal(normal.value, '#0c0905');
  normal.onChange(undefined);
  assert.equal(saved.button.color, undefined);

  assert.deepEqual(Object.keys(colourFieldProps({ type: 'core.text', style: {} }, 'text', 'normal', ctx, () => {})), ['effective'], 'no part: the block keeps its own writes');
});

test('other fields held by the part are read and written there', () => {
  let saved = null;
  const size = partField(button, 'typography.size', ctx, (next) => { saved = next; });
  assert.equal(size.value, '15px');
  size.put('18px');
  assert.equal(saved.button.typography.size, '18px');
  assert.equal(partField(button, 'typography.weight', ctx, () => {}), null);
});
