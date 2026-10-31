// Container width: Default follows the site's content width (Site settings › Layout), the presets show this site's
// sizes, Custom takes a size per device, and a pick shows on the canvas at once (before the save and the repaint).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { isStructuralChange } from '../src/core/canvasLiveSync.mjs';
import { compileBlockStyle } from '../src/core/liveStyle.mjs';
import { boxWidthLabel, boxWidthOf, boxWidths, siteContentPx, siteToken } from '../src/core/siteWidths.mjs';
import { acceptsDraft, isSize } from '../src/core/styleValues.mjs';
import { newContainer } from '../src/core/operations.mjs';
import { t } from '../src/core/messages.mjs';

const tokens = { has: () => true };
const boxed = (layout, extra = {}) => ({ id: 's1', type: 'core.container', style: { layout: { content_width: 'boxed', gutter: 'site', ...layout } }, children: [], ...extra });
const docOf = (top) => ({ containers: [top] });

test('a width pick is painted on the canvas at once', () => {
  const before = boxed({ content_size: 'wide' });
  const after = boxed({ content_size: 'narrow' });
  assert.equal(isStructuralChange(docOf(before), docOf(after), tokens), false, 'no repaint from the server is needed');
  const css = compileBlockStyle(after, tokens).scoped;
  assert.ok(css.includes('--sb-box:var(--sb-layout-narrow,42rem)'), css.join(';'));
  assert.ok(compileBlockStyle(before, tokens).scoped.includes('--sb-box:var(--sb-layout-wide,80rem)'));
});

test('gap, columns and vertical spacing are painted too', () => {
  const after = boxed({ display: 'grid', columns: 3, gap: '2rem', padding_y: 'lg' });
  assert.equal(isStructuralChange(docOf(boxed({})), docOf(after), tokens), false);
  const css = compileBlockStyle(after, tokens).scoped;
  for (const d of ['display:grid', 'gap:2rem', 'grid-template-columns:repeat(3,minmax(0,1fr))', 'padding-top:calc(var(--sb-space-lg,3rem)*4/3)', 'padding-bottom:calc(var(--sb-space-lg,3rem)*4/3)']) assert.ok(css.includes(d), `${d} in ${css.join(';')}`);
});

test('a custom width writes the box per device, and drops a bad size', () => {
  const custom = boxed({ content_max: '100%' }, { responsive: { tablet: { style: { layout: { content_max: '90vw' } } }, mobile: { style: { layout: { content_max: '960px;color:red' } } } } });
  const out = compileBlockStyle(custom, tokens);
  assert.equal(boxWidthOf(custom.style.layout), 'custom');
  assert.ok(out.scoped.includes('--sb-box:100%'), out.scoped.join(';'));
  const device = out.media.flatMap((m) => m.declarations);
  assert.ok(device.includes('--sb-box:90vw !important'), device.join(';'));
  assert.ok(!device.some((d) => d.includes('960px')), 'an invalid size is left out, as the server leaves it out');

  // Back to a preset: no own size is left.
  const preset = boxed({ content_size: 'normal' });
  assert.equal(boxWidthOf(preset.style.layout), 'normal');
  assert.ok(compileBlockStyle(preset, tokens).scoped.includes('--sb-box:var(--sb-layout-normal,64rem)'));
  assert.equal(compileBlockStyle(preset, tokens).media.length, 0);
});

test('a size is checked like the other size fields: px, %, rem, vw, never negative or bare', () => {
  for (const ok of ['960px', '90%', '60rem', '80vw', 'min(1170px, 100%)', '0']) assert.ok(isSize(ok), ok);
  for (const bad of ['960', '-10px', 'auto', 'none', 'inherit', '960px;color:red', '', 12]) assert.ok(!isSize(bad), String(bad));
  assert.ok(acceptsDraft('size', ''), 'empty clears the device');
  assert.ok(!acceptsDraft('size', 'wide'));
});

test('the width list comes from the manifest, ends with Custom, and labels each width with this site\'s size', () => {
  const manifest = {
    tokens: [
      { category: 'layout', ref: 'layout.content', value: '1170px' },
      { category: 'layout', ref: 'layout.narrow', value: '42rem' },
      { category: 'layout', ref: 'layout.wide', value: '75rem' },
    ],
  };
  assert.deepEqual(boxWidths({ vocabulary: { container_widths: ['default', 'narrow', 'normal', 'wide', 'full'] } }), ['default', 'narrow', 'normal', 'wide', 'full', 'custom']);
  assert.deepEqual(boxWidths({ vocabulary: { container_widths: ['narrow', 'full'] } }), ['narrow', 'full', 'custom'], 'the server decides the list');
  assert.deepEqual(boxWidths({}), ['default', 'narrow', 'normal', 'wide', 'full', 'custom'], 'no manifest list: the standard one');
  assert.equal(boxWidthLabel(manifest, 'default'), `${t('opt_default')} · 1170px`);
  assert.equal(boxWidthLabel(manifest, 'wide'), `${t('opt_wide')} · 1200px`, 'the site value, not the platform default');
  assert.equal(boxWidthLabel(manifest, 'narrow'), `${t('opt_narrow')} · 672px`);
  assert.equal(boxWidthLabel(manifest, 'normal'), `${t('opt_normal')} · 1024px`, 'a token the manifest lacks falls back to the table');
  assert.equal(boxWidthLabel(manifest, 'custom'), t('opt_custom'));
  assert.equal(siteToken(manifest, 'layout.content'), '1170px');
  assert.equal(siteContentPx(manifest), '1170');
  assert.equal(siteContentPx({ tokens: [] }), '1280', 'the platform default');
});

test('a new Container follows the site content width', () => {
  const layout = newContainer().style.layout;
  assert.equal(layout.content_size, undefined);
  assert.equal(layout.content_max, undefined);
  assert.equal(boxWidthOf(layout), 'default');
});
