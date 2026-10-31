// Editor fixes from the Studio Builder gap report: canvas drops follow the manifest's container rule (CL-M6),
// selecting a block never moves it (CL-M10), a Container's spacing control a style overrides says so (CL-M8),
// the older per-device hide checkboxes are not offered for new edits (CL-M5), and Site settings say which tokens
// only blocks use (DT-M7).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { canvasDropPosition } from '../src/core/canvas.mjs';
import { legacyHideDevices } from '../src/core/responsiveStyle.mjs';
import { paddingBeatsSpacing } from '../src/core/styleSurface.mjs';
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
  assert.equal(canvasDropPosition({ type: 'core.container', tagName: 'section', fraction: 0.5, allowsChildren }), 'inside', 'a top-level Container does');
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

test('Container spacing: a top or bottom padding in the style marks the spacing control overridden (CL-M8)', () => {
  assert.equal(paddingBeatsSpacing({}), false);
  assert.equal(paddingBeatsSpacing({ padding: { top: '2rem' } }), true);
  assert.equal(paddingBeatsSpacing({ padding: { bottom: '2rem' } }), true);
  assert.equal(paddingBeatsSpacing({ padding: { top: '' } }), false, 'an empty value is not set');
  assert.equal(paddingBeatsSpacing({ padding: { left: '2rem' } }), false, 'side padding does not override vertical padding');
  assert.match(src('../src/components/inspectors/ContainerPane.jsx'), /paddingBeatsSpacing\(style\) && <p className="sbx-hint">\{t\('container_padding_overridden'\)\}/);
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
