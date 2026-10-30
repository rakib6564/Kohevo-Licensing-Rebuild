import test from 'node:test';
import assert from 'node:assert/strict';
import { inlineSpecsFor, inlineTextValue, propsWithInlineText, resolveInlineTarget } from '../src/core/inlineText.mjs';
import { manifest } from './helpers.mjs';

const node = (type) => ({ id: 'blk_1', type, props: {} });

test('only the blocks that declare inline text are editable in place, and each names its prop and element', () => {
  const declared = manifest.blocks.filter((b) => (b.inline_text || []).length > 0).map((b) => b.type).sort();
  assert.deepEqual(declared, ['core.button', 'core.feature_list', 'core.heading', 'core.hero', 'core.link', 'core.nav_menu', 'core.quote', 'core.text']);
  // A linked heading's text sits in its link, and a button with an icon has a label span: those come first, the element is the fallback.
  assert.deepEqual(inlineSpecsFor(manifest, node('core.heading')), [
    { prop: 'text', selector: '.sb-heading__link', multiline: false },
    { prop: 'text', selector: '.sb-heading', multiline: false },
  ]);
  assert.deepEqual(inlineSpecsFor(manifest, node('core.button')).map((s) => s.selector), ['.sb-button__label', '.sb-button']);
  assert.equal(inlineSpecsFor(manifest, node('core.text'))[0].multiline, true, 'a text field keeps its line breaks');
  assert.deepEqual(inlineSpecsFor(manifest, node('core.image')), []);
  assert.deepEqual(inlineSpecsFor(manifest, node('core.card')), []);
  assert.deepEqual(inlineSpecsFor(manifest, node('x.unknown')), []);
});

test('every declared prop is a text prop of the block, so an edit can never write an unknown prop', () => {
  for (const b of manifest.blocks) {
    for (const s of b.inline_text || []) {
      const [key, sub, itemKey] = s.prop.split('.');
      const field = b.field_schema.find((f) => f.key === key);
      assert.ok(field, `${b.type}.${s.prop} exists`);
      if (sub === '*') {
        const item = (field.item_schema || []).find((f) => f.key === itemKey);
        assert.ok(field.type === 'repeater' && item && ['string', 'text'].includes(item.type), `${b.type}.${s.prop} is a text field of every item`);
      } else {
        assert.ok(sub ? (sub === 'label' && field.type === 'link') : ['string', 'text'].includes(field.type), `${b.type}.${s.prop} is text`);
      }
      if (s.unless !== undefined) assert.ok(b.field_schema.some((f) => f.key === s.unless), `${b.type} unless ${s.unless} is a prop`);
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

test('a menu edits each link in place: the n-th link writes item n, and the list stays a list', () => {
  const links = [0, 1, 2].map((i) => ({ i, contains: (t) => t === links[i] }));
  const nav = { querySelector: () => links[0], querySelectorAll: (sel) => (sel === '.sb-nav__list > .sb-nav__item > .sb-nav__link' ? links : []) };
  const specs = inlineSpecsFor(manifest, { type: 'core.nav_menu', props: { menu: '' } });
  assert.deepEqual(specs.map((sp) => sp.prop), ['items.*.label']);
  const hit = resolveInlineTarget(nav, specs, links[2]);
  assert.equal(hit.el, links[2]);
  assert.equal(hit.spec.prop, 'items.2.label', 'the third link names the third item');
  assert.equal(resolveInlineTarget(nav, specs, null).spec.prop, 'items.0.label', 'Edit starts at the first link');
  assert.equal(resolveInlineTarget({ querySelector: () => null, querySelectorAll: () => [] }, specs, null), null, 'no links, nothing to edit');

  const props = { menu: '', items: [{ label: 'Home', url: '/' }, { label: 'About', url: '/about' }, { label: 'Contact', url: '/contact' }] };
  const next = propsWithInlineText(props, 'items.2.label', 'Say hello');
  assert.ok(Array.isArray(next.items), 'still a list');
  assert.deepEqual(next.items[2], { label: 'Say hello', url: '/contact' });
  assert.deepEqual(next.items.slice(0, 2), props.items.slice(0, 2), 'the other links are untouched');
  assert.equal(props.items[2].label, 'Contact', 'the original props are not mutated');
  assert.equal(inlineTextValue(next, 'items.2.label'), 'Say hello');
  assert.deepEqual(propsWithInlineText(props, 'items.9.label', 'x'), props, 'an index past the end changes nothing');
});

test('a menu chosen on the Menus screen shows its own links, so the block\'s links are not edited in place then', () => {
  assert.deepEqual(inlineSpecsFor(manifest, { type: 'core.nav_menu', props: { menu: 'header' } }), []);
  assert.equal(inlineSpecsFor(manifest, { type: 'core.nav_menu', props: {} }).length, 1, 'no menu chosen: editable');
});

test('a link edits its label in place', () => {
  assert.deepEqual(inlineSpecsFor(manifest, { type: 'core.link', props: {} }), [{ prop: 'link.label', selector: '.sb-link', multiline: false }]);
  assert.deepEqual(propsWithInlineText({ link: { label: 'Your brand', href: '/' }, style: 'plain' }, 'link.label', 'Solaya'), { link: { label: 'Solaya', href: '/' }, style: 'plain' });
});
