// Section width: Default follows the site's content width (Site settings › Layout), the presets show this site's
// sizes, Custom takes a size per device, and a pick shows on the canvas at once (before the save and the repaint).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { syncLiveDOM } from '../src/core/canvasLiveSync.mjs';
import { sectionWidthLabel, sectionWidths, siteContentPx, siteToken } from '../src/core/siteWidths.mjs';
import { acceptsDraft, isSize } from '../src/core/styleValues.mjs';
import { DEFAULT_SECTION_LAYOUT } from '../src/core/operations.mjs';
import { t } from '../src/core/messages.mjs';

/** A canvas element with real class and custom property bookkeeping. */
function el(classes = []) {
  const set = new Set(classes);
  const props = new Map();
  return {
    classList: {
      add: (...c) => c.forEach((x) => set.add(x)),
      remove: (...c) => c.forEach((x) => set.delete(x)),
      contains: (c) => set.has(c),
      [Symbol.iterator]: () => set.values(),
    },
    style: { setProperty: (k, v) => props.set(k, v), removeProperty: (k) => props.delete(k), getPropertyValue: (k) => props.get(k) || '' },
    classes: () => [...set].sort(),
    props,
  };
}

function canvasWith(section, inner) {
  section.querySelector = (sel) => (sel === ':scope > .sb-section__inner' ? inner : null);
  return { querySelector: (sel) => (sel.includes('data-sb-node="s1"') ? section : null) };
}

const sectionDoc = (layout, blocks = [{ id: 'b1', type: 'core.heading', props: {} }]) => ({ sections: [{ id: 's1', layout, blocks }] });

test('a width pick is painted on the canvas at once', () => {
  const section = el(['sb-section', 'sb-py-md', 'sb-md-py-lg']);
  const inner = el(['sb-section__inner', 'sb-w-wide', 'sb-gap-md', 'sb-cols-1']);
  const before = sectionDoc({ width: 'wide', gap: 'md', columns: { base: 1 }, padding_y: { base: 'md', md: 'lg' } });
  const after = sectionDoc({ width: 'narrow', gap: 'md', columns: { base: 1 }, padding_y: { base: 'md', md: 'lg' } });
  assert.equal(syncLiveDOM(canvasWith(section, inner), before, after), 1);
  assert.deepEqual(inner.classes(), ['sb-cols-1', 'sb-gap-md', 'sb-section__inner', 'sb-w-narrow']);
  assert.deepEqual(section.classes(), ['sb-md-py-lg', 'sb-py-md', 'sb-section']);
});

test('gap, columns (capped at the block count) and vertical padding are painted too', () => {
  const section = el(['sb-section', 'sb-py-md']);
  const inner = el(['sb-section__inner', 'sb-w-default', 'sb-gap-md', 'sb-cols-1']);
  const blocks = [{ id: 'b1' }, { id: 'b2' }];
  syncLiveDOM(canvasWith(section, inner), sectionDoc({ width: 'default' }, blocks), sectionDoc({ width: 'default', gap: 'xl', columns: { base: 1, md: 3 }, padding_y: { base: 'none', lg: 'xl' } }, blocks));
  assert.deepEqual(inner.classes(), ['sb-cols-1', 'sb-gap-xl', 'sb-md-cols-2', 'sb-section__inner', 'sb-w-default']);
  assert.deepEqual(section.classes(), ['sb-lg-py-xl', 'sb-py-none', 'sb-section']);
});

test('a custom width writes the per-device properties the stylesheet reads, and drops a bad size', () => {
  const section = el(['sb-section']);
  const inner = el(['sb-section__inner', 'sb-w-wide', 'sb-gap-md']);
  const canvas = canvasWith(section, inner);
  const custom = sectionDoc({ width: 'custom', custom_width: { base: '100%', md: '90vw', lg: '960px;color:red' } });
  syncLiveDOM(canvas, sectionDoc({ width: 'wide' }), custom);
  assert.ok(inner.classList.contains('sb-w-custom'));
  assert.ok(inner.classList.contains('sb-md-cw'));
  assert.ok(!inner.classList.contains('sb-lg-cw'), 'an invalid size is left out, as the server leaves it out');
  assert.equal(inner.props.get('--sb-cw'), '100%');
  assert.equal(inner.props.get('--sb-cw-md'), '90vw');
  assert.equal(inner.props.has('--sb-cw-lg'), false);

  // Back to a preset: the custom properties and classes go.
  syncLiveDOM(canvas, custom, sectionDoc({ width: 'normal' }));
  assert.deepEqual(inner.classes(), ['sb-gap-md', 'sb-section__inner', 'sb-w-normal']);
  assert.equal(inner.props.size, 0);
});

test('a size is checked like the other size fields: px, %, rem, vw, never negative or bare', () => {
  for (const ok of ['960px', '90%', '60rem', '80vw', 'min(1170px, 100%)', '0']) assert.ok(isSize(ok), ok);
  for (const bad of ['960', '-10px', 'auto', 'none', 'inherit', '960px;color:red', '', 12]) assert.ok(!isSize(bad), String(bad));
  assert.ok(acceptsDraft('size', ''), 'empty clears the device');
  assert.ok(!acceptsDraft('size', 'wide'));
});

test('the width list starts with Default, ends with Custom, and labels each width with this site\'s size', () => {
  const manifest = {
    tokens: [
      { category: 'layout', ref: 'layout.content', value: '1170px' },
      { category: 'layout', ref: 'layout.narrow', value: '42rem' },
      { category: 'layout', ref: 'layout.wide', value: '75rem' },
    ],
  };
  assert.deepEqual(sectionWidths({ section_widths: ['default', 'narrow', 'normal', 'wide', 'full', 'custom'] }), ['default', 'narrow', 'normal', 'wide', 'full', 'custom']);
  assert.deepEqual(sectionWidths({ container_widths: ['narrow', 'normal', 'wide', 'full'] }), ['narrow', 'normal', 'wide', 'full', 'custom'], 'an older server');
  assert.equal(sectionWidthLabel(manifest, 'default'), `${t('opt_default')} · 1170px`);
  assert.equal(sectionWidthLabel(manifest, 'wide'), `${t('opt_wide')} · 1200px`, 'the site value, not the platform default');
  assert.equal(sectionWidthLabel(manifest, 'narrow'), `${t('opt_narrow')} · 672px`);
  assert.equal(sectionWidthLabel(manifest, 'normal'), `${t('opt_normal')} · 1024px`, 'a token the manifest lacks falls back to the table');
  assert.equal(sectionWidthLabel(manifest, 'custom'), t('opt_custom'));
  assert.equal(siteToken(manifest, 'layout.content'), '1170px');
  assert.equal(siteContentPx(manifest), '1170');
  assert.equal(siteContentPx({ tokens: [] }), '1280', 'the platform default');
});

test('a new section follows the site content width', () => {
  assert.equal(DEFAULT_SECTION_LAYOUT.width, 'default');
});
