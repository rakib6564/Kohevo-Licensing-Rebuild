import { test } from 'node:test';
import assert from 'node:assert/strict';
import { COLOR_VALUE, controlFor, hint, swatchHex } from '../src/core/fields.mjs';

test('a string field with format colour gets the colour control; plain strings stay text', () => {
  assert.equal(controlFor({ type: 'string', format: 'color' }), 'color');
  assert.equal(controlFor({ type: 'string' }), 'text');
  assert.equal(controlFor({ type: 'token_ref' }), 'token');
});

test('literal colours: hex and rgb()/hsl() pass, anything that could close a declaration does not', () => {
  for (const ok of ['#e4572e', '#abc', '#aabbccdd', 'rgb(228 87 46)', 'rgba(0,0,0,.5)', 'hsl(12 50% 40%)']) assert.ok(COLOR_VALUE.test(ok), ok);
  for (const bad of ['red', 'e4572e', '#12', '#12345', 'rgb(1;color:red)', 'url(x)', 'expression(1)', '#fff;x:y']) assert.ok(!COLOR_VALUE.test(bad), bad);
  const field = { key: 'highlight_color', type: 'string', format: 'color' };
  assert.equal(hint(field, ''), null, 'empty means no custom colour');
  assert.equal(hint(field, '#e4572e'), null);
  assert.equal(hint(field, 'orange'), 'field_colour');
});

test('the swatch shows the six-digit form, and nothing for a value it cannot show', () => {
  assert.equal(swatchHex('#E4572E'), '#e4572e');
  assert.equal(swatchHex('#abc'), '#aabbcc');
  assert.equal(swatchHex('rgb(1 2 3)'), null);
  assert.equal(swatchHex(''), null);
  assert.equal(swatchHex(null), null);
});

import { highlightMissing } from '../src/core/fields.mjs';

test('highlightMissing: warns only when a phrase is set and absent from the text', () => {
  assert.equal(highlightMissing({ text: 'Maple & Co.', highlight: 'ok' }), true);
  assert.equal(highlightMissing({ text: 'Maple & Co.', highlight: 'Maple' }), false);
  assert.equal(highlightMissing({ text: 'Maple & Co.', highlight: '  ' }), false);
  assert.equal(highlightMissing({ heading: 'Welcome home', highlight: 'home' }), false); // a hero's text is `heading`
  assert.equal(highlightMissing({ heading: 'Welcome home', highlight: 'away' }), true);
  assert.equal(highlightMissing({}), false);
});
