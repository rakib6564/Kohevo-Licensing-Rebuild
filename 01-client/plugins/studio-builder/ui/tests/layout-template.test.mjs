// The Container Inspector's width note: shown only when the page's Single template keeps this Container narrower live.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { templateCapsWidth } from '../src/core/layoutTemplate.mjs';

const tpl = (content_width) => ({ id: 4, title: 'Journal article', content_width });

test('a Container wider than the template content is capped; the same width or narrower is not', () => {
  assert.equal(templateCapsWidth(tpl('narrow'), 'wide'), true);
  assert.equal(templateCapsWidth(tpl('narrow'), 'full'), true);
  assert.equal(templateCapsWidth(tpl('wide'), 'full'), true);
  assert.equal(templateCapsWidth(tpl('narrow'), 'narrow'), false);
  assert.equal(templateCapsWidth(tpl('wide'), 'normal'), false);
  assert.equal(templateCapsWidth(tpl('full'), 'full'), false);
});

test('a Container with no width counts as wide, as it renders', () => {
  assert.equal(templateCapsWidth(tpl('normal'), undefined), true);
  assert.equal(templateCapsWidth(tpl('wide'), ''), false);
});

test('no template, a template without Post content, or unknown widths: no note', () => {
  assert.equal(templateCapsWidth(null, 'full'), false);
  assert.equal(templateCapsWidth(tpl(null), 'full'), false);
  assert.equal(templateCapsWidth(tpl('huge'), 'full'), false);
  assert.equal(templateCapsWidth(tpl('narrow'), 'huge'), false);
});
