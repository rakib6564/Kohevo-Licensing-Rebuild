import { test } from 'node:test';
import assert from 'node:assert/strict';
import { setBorderLiteral, setBorderToken, setSpacingToken, setSurfaceToken, setTextLiteral, setTextToken } from '../src/core/tokenStyle.mjs';

test('a theme text colour removes the literal colours it would lose to', () => {
  const next = setTextToken({ color: '#111', typography: { color: '#222', size: '2rem' }, shadow: 'md' }, 'text.muted');
  assert.deepEqual(next, { typography: { size: '2rem' }, shadow: 'md', text_token: 'text.muted' });
});

test('a theme text colour leaves no empty typography shell', () => {
  assert.deepEqual(setTextToken({ typography: { color: '#222' } }, 'text.body'), { text_token: 'text.body' });
});

test('clearing the theme text colour keeps the literal alone', () => {
  assert.deepEqual(setTextToken({ text_token: 'text.body', typography: { size: '1rem' } }, null), { typography: { size: '1rem' } });
});

test('a literal text colour replaces the theme colour and folds the old flat colour in', () => {
  assert.deepEqual(setTextLiteral({ text_token: 'text.body', color: '#111' }, '#abc'), { typography: { color: '#abc' } });
  assert.deepEqual(setTextLiteral({ typography: { color: '#abc', size: '1rem' } }, undefined), { typography: { size: '1rem' } });
});

test('a theme surface colour removes the literal background colour but keeps an image or gradient', () => {
  assert.deepEqual(setSurfaceToken({ background: { color: '#fff', gradient: 'linear-gradient(0deg, #000, #fff)' } }, 'surface.alt'),
    { background: { gradient: 'linear-gradient(0deg, #000, #fff)' }, surface_token: 'surface.alt' });
  assert.deepEqual(setSurfaceToken({ background: '#fff' }, 'surface.alt'), { surface_token: 'surface.alt' });
  assert.deepEqual(setSurfaceToken({ background: 'linear-gradient(0deg, #000, #fff)' }, 'surface.alt').background, 'linear-gradient(0deg, #000, #fff)');
});

test('a theme spacing replaces literal padding and clearing it leaves the rest', () => {
  assert.deepEqual(setSpacingToken({ padding: { top: '1rem' }, margin: { top: '2rem' } }, 'space.md'), { margin: { top: '2rem' }, spacing_token: 'space.md' });
  assert.deepEqual(setSpacingToken({ spacing_token: 'space.md' }, null), {});
});

test('the input is never changed', () => {
  const style = { typography: { color: '#222' } };
  setTextToken(style, 'text.body');
  assert.deepEqual(style, { typography: { color: '#222' } });
});

test('a theme border colour removes the literal border colour and keeps the rest of the border', () => {
  assert.deepEqual(setBorderToken({ border: { color: '#e8734a', style: 'solid' } }, 'border.default'), { border: { style: 'solid' }, border_token: 'border.default' });
  assert.deepEqual(setBorderToken({ border: { color: '#e8734a' } }, 'border.default'), { border_token: 'border.default' });
  assert.deepEqual(setBorderToken({ border_token: 'border.default', border: { style: 'solid' } }, null), { border: { style: 'solid' } });
});

test('a literal border colour replaces the theme border colour', () => {
  assert.deepEqual(setBorderLiteral({ border_token: 'border.default', border: { style: 'solid' } }, '#abc'), { border: { style: 'solid', color: '#abc' } });
  assert.deepEqual(setBorderLiteral({ border: { color: '#abc' } }, undefined), {});
});
