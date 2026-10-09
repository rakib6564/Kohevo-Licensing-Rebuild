import test from 'node:test';
import assert from 'node:assert/strict';
import { byteLength, cssState } from '../src/core/customCss.mjs';

test('size is measured in UTF-8 bytes, like the server', () => {
  assert.equal(byteLength(''), 0);
  assert.equal(byteLength('a{}'), 3);
  assert.equal(byteLength('é'), 2);
  assert.equal(byteLength('€'), 3);
});

test('a draft is dirty only when it differs from what is stored, ignoring outer whitespace', () => {
  assert.equal(cssState('a{}', 'a{}', 100).dirty, false);
  assert.equal(cssState('  a{}\n', 'a{}', 100).dirty, false);
  assert.equal(cssState('a{color:red}', 'a{}', 100).dirty, true);
});

test('over the ceiling is flagged', () => {
  assert.equal(cssState('x'.repeat(11), '', 10).tooLarge, true);
  assert.equal(cssState('x'.repeat(10), '', 10).tooLarge, false);
});
