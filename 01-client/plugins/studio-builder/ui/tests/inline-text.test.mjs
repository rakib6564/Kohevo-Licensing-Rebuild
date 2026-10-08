import test from 'node:test';
import assert from 'node:assert/strict';
import { inlineSpecsFor, inlineTextValue, propsWithInlineText, resolveInlineTarget } from '../src/core/inlineText.mjs';
import { manifest } from './helpers.mjs';

const node = (type) => ({ id: 'blk_1', type, props: {} });

test('only the blocks that declare inline text are editable in place, and each names its prop and element', () => {
  const declared = manifest.blocks.filter((b) => (b.inline_text || []).length > 0).map((b) => b.type).sort();
  assert.deepEqual(declared, ['core.button', 'core.feature_list', 'core.heading', 'core.hero', 'core.quote', 'core.text']);
  assert.deepEqual(inlineSpecsFor(manifest, node('core.heading')), [{ prop: 'text', selector: '.sb-heading', multiline: false }]);
  assert.deepEqual(inlineSpecsFor(manifest, node('core.button')).map((s) => s.prop), ['link.label']);
  assert.equal(inlineSpecsFor(manifest, node('core.text'))[0].multiline, true, 'a text field keeps its line breaks');
  assert.deepEqual(inlineSpecsFor(manifest, node('core.image')), []);
  assert.deepEqual(inlineSpecsFor(manifest, node('core.card')), []);
  assert.deepEqual(inlineSpecsFor(manifest, node('x.unknown')), []);
});

test('every declared prop is a text prop of the block, so an edit can never write an unknown prop', () => {
  for (const b of manifest.blocks) {
    for (const s of b.inline_text || []) {
      const [key, sub] = s.prop.split('.');
      const field = b.field_schema.find((f) => f.key === key);
      assert.ok(field, `${b.type}.${s.prop} exists`);
      assert.ok(sub ? (sub === 'label' && field.type === 'link') : ['string', 'text'].includes(field.type), `${b.type}.${s.prop} is text`);
    }
  }
});

test('an edit writes the declared prop and leaves the rest of the props alone', () => {
  assert.deepEqual(propsWithInlineText({ text: 'Old', level: 'h2' }, 'text', 'New'), { text: 'New', level: 'h2' });
  const link = { label: 'Go', href: '/x', target: '_blank' };
  assert.deepEqual(propsWithInlineText({ link, variant: 'primary' }, 'link.label', 'Book now'), { link: { label: 'Book now', href: '/x', target: '_blank' }, variant: 'primary' });
  assert.equal(inlineTextValue({ link }, 'link.label'), 'Go');
  assert.equal(inlineTextValue({}, 'text'), '');
});

test('resolveInlineTarget finds the declared element under the pointer, or the first one for Edit', () => {
  const heading = { contains: (t) => t === heading || t === child };
  const child = {};
  const eyebrow = { contains: (t) => t === eyebrow };
  const els = { '.sb-hero__heading': heading, '.sb-hero__eyebrow': eyebrow };
  const nodeEl = { querySelector: (sel) => els[sel] || null };
  const specs = [{ prop: 'heading', selector: '.sb-hero__heading' }, { prop: 'eyebrow', selector: '.sb-hero__eyebrow' }, { prop: 'subheading', selector: '.sb-hero__subheading' }];
  assert.equal(resolveInlineTarget(nodeEl, specs, child).spec.prop, 'heading');
  assert.equal(resolveInlineTarget(nodeEl, specs, eyebrow).spec.prop, 'eyebrow');
  assert.equal(resolveInlineTarget(nodeEl, specs, {}), null, 'something else in the block: nothing to edit');
  assert.equal(resolveInlineTarget(nodeEl, specs).spec.prop, 'heading', 'Edit without a pointer takes the first');
  assert.equal(resolveInlineTarget(nodeEl, [], child), null);
});
