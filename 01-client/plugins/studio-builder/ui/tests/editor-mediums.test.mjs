// Editor fixes from the Studio Builder gap report: canvas drops follow the manifest's container rule (CL-M6),
// selecting a block never moves it (CL-M10), section Layout controls a style overrides are shown as such (CL-M8),
// the older per-device hide checkboxes are not offered for new edits (CL-M5), and Site settings say which tokens
// only blocks use (DT-M7).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { canvasDropPosition } from '../src/core/canvas.mjs';
import { legacyHideDevices } from '../src/core/responsiveStyle.mjs';
import { sectionStyleOverrides } from '../src/core/styleSurface.mjs';
import { BLOCK_ONLY_TOKENS, tokenUsedOnlyByBlocks } from '../src/core/siteSettings.mjs';
import { blockDefinition } from '../src/core/doc.mjs';

const manifest = JSON.parse(readFileSync(new URL('./fixtures/manifest.json', import.meta.url), 'utf8'));
const allowsChildren = (type) => Boolean(blockDefinition(manifest, type)?.allows_children);
const src = (path) => readFileSync(new URL(path, import.meta.url), 'utf8');

test('canvas drop: every block the manifest lets hold children takes a drop inside, as in Layers (CL-M6)', () => {
  for (const type of ['core.card', 'core.container', 'core.modal', 'core.form', 'core.query_loop', 'layout.flex']) {
    assert.equal(canvasDropPosition({ type, tagName: 'div', fraction: 0.5, allowsChildren }), 'inside', type);
  }
  assert.equal(canvasDropPosition({ type: 'core.heading', tagName: 'section', fraction: 0.5, allowsChildren }), 'after', 'a leaf block that renders a <section> does not');
  assert.equal(canvasDropPosition({ type: 'section', tagName: 'section', fraction: 0.5, allowsChildren }), 'inside', 'a document section does');
  assert.equal(canvasDropPosition({ type: 'core.card', fraction: 0.1, allowsChildren }), 'before', 'the top quarter is before');
  assert.equal(canvasDropPosition({ type: 'core.card', fraction: 0.9, allowsChildren }), 'after', 'the bottom quarter is after');
  assert.equal(canvasDropPosition({ type: 'layout.grid', fraction: 0.5 }), 'inside', 'without the manifest rule, layout blocks still take drops');
  assert.match(src('../src/components/CanvasArea.jsx'), /allowsChildren: \(type\) => Boolean\(blockDefinition\(manifest, type\)\?\.allows_children\)/, 'the canvas is given the manifest rule');
});

test('canvas selection: it never changes the selected block\'s position (CL-M10)', () => {
  const canvas = src('../src/core/canvas.mjs');
  const css = canvas.slice(canvas.indexOf('const OVERLAY_CSS'), canvas.indexOf('`;', canvas.indexOf('const OVERLAY_CSS')));
  assert.doesNotMatch(css, /position:\s*relative\s*!important/, 'no position override on the selected block');
  assert.doesNotMatch(css, /\.sbx-selected::(before|after)/, 'and no handles that would need one');
  assert.match(css, /\.sbx-selected \{\s*\n?\s*outline:/, 'the selection outline stays');
});

test('section Layout: a style background or vertical padding marks the matching Layout control overridden (CL-M8)', () => {
  assert.deepEqual(sectionStyleOverrides({}), { background: false, paddingY: false });
  assert.deepEqual(sectionStyleOverrides({ background: { color: '#fff' } }), { background: true, paddingY: false });
  assert.equal(sectionStyleOverrides({ background: { image: { media_id: 4 } } }).background, true, 'an image too');
  assert.equal(sectionStyleOverrides({ background: { color: '' } }).background, false, 'an empty value is not set');
  assert.deepEqual(sectionStyleOverrides({ padding: { top: '2rem' } }), { background: false, paddingY: true });
  assert.equal(sectionStyleOverrides({ padding: { left: '2rem' } }).paddingY, false, 'side padding does not override vertical padding');
  const inspector = src('../src/components/inspectors/SectionInspector.jsx');
  assert.match(inspector, /owned\(overridden\.paddingY, 'section_padding_overridden'/);
  assert.match(inspector, /owned\(overridden\.background, 'section_bg_overridden'/);
});

test('block Responsive: the older hide checkboxes show only for a stored value, so they can be removed (CL-M5)', () => {
  assert.deepEqual(legacyHideDevices(undefined), []);
  assert.deepEqual(legacyHideDevices({ tablet: { style: { padding: {} } } }), [], 'a device override without hide offers no checkbox');
  assert.deepEqual(legacyHideDevices({ mobile: { hide: true }, tablet: { hide: false } }), ['mobile']);
  assert.match(src('../src/components/inspectors/BlockInspector.jsx'), /legacyHideDevices\(responsive\)\.length > 0 &&/, 'no fieldset at all for a block without one');
});

test('Site settings: tokens nothing uses by default say so (DT-M7)', () => {
  assert.deepEqual([...BLOCK_ONLY_TOKENS].sort(), ['surface.inverse', 'surface.muted', 'text.accent']);
  assert.equal(tokenUsedOnlyByBlocks('surface.muted'), true);
  assert.equal(tokenUsedOnlyByBlocks('surface.page'), false);
  assert.match(src('../src/components/SiteSettings.jsx'), /tokenUsedOnlyByBlocks\(tk\.ref\) && <p className="sbx-hint">\{t\('ss_tk_block_only'\)\}<\/p>/);
});
