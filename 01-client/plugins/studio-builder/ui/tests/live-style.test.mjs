import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { compileBlockStyle, ruleText, tokenClassRules, MANAGED_CLASS, num } from '../src/core/liveStyle.mjs';

const fixture = JSON.parse(readFileSync(new URL('./fixtures/live-style.json', import.meta.url), 'utf8'));
const tokens = new Set(fixture.tokens);
const sorted = (list) => [...list].sort();

test('every fixture case compiles to exactly what the server renderer wrote', () => {
  assert.ok(fixture.cases.length >= 40);
  for (const c of fixture.cases) {
    const got = compileBlockStyle(c.block, tokens);
    assert.equal(got.covered, c.covered, `${c.name}: covered`);
    if (!c.covered) continue;
    assert.deepEqual(sorted(got.scoped), c.expected.scoped, `${c.name}: scoped rule`);
    assert.deepEqual(sorted(got.inline), c.expected.inline, `${c.name}: inline style`);
    assert.deepEqual(sorted(got.classes), c.expected.classes, `${c.name}: classes`);
    assert.equal(got.reduceMotion, c.expected.reduceMotion, `${c.name}: reduced-motion override`);
    assert.deepEqual(got.media.map((m) => ({ query: m.query, declarations: m.declarations })), c.expected.media, `${c.name}: device rules`);
  }
});

test('a block with nothing to predict is covered and empty', () => {
  const got = compileBlockStyle({ style: {} }, tokens);
  assert.deepEqual([got.covered, got.scoped, got.inline, got.classes, got.media], [true, [], [], [], []]);
  assert.equal(compileBlockStyle(undefined, tokens).covered, true);
});

test('numbers are formatted as the server formats them', () => {
  assert.equal(num(1.1), '1.1');
  assert.equal(num(15), '15');
  assert.equal(num(0.30000000000000004), '0.3');
  assert.equal(num(-0), '0');
  assert.equal(num(2.0004), '2');
});

test('the rule text outweighs the server class rule and carries the device queries', () => {
  const c = fixture.cases.find((x) => x.name === 'responsive tablet and mobile');
  const css = ruleText('blk_abc', compileBlockStyle(c.block, tokens));
  assert.match(css, /@media \(max-width:1023\.98px\)\{\[data-sb-node="blk_abc"\]\[data-sb-node\]\{font-size:28px !important/);
  const flex = fixture.cases.find((x) => x.name === 'flex layout');
  assert.match(ruleText('blk_abc', compileBlockStyle(flex.block, tokens)), /^\[data-sb-node="blk_abc"\]\[data-sb-node\]\{display:flex/);
  assert.equal(ruleText('blk_abc', compileBlockStyle({ style: {} }, tokens)), '');
});

test('a node id cannot break out of the selector', () => {
  const css = ruleText('x"] , body { display:none } [y="', { scoped: ['gap:1rem'], media: [] });
  assert.ok(!css.includes('body {') && !css.includes('"]'.concat(' ,')), css);
});

test('token classes get their rule so an unused token still paints', () => {
  const c = fixture.cases.find((x) => x.name === 'token classes that exist');
  const rules = tokenClassRules(compileBlockStyle(c.block, tokens).classes);
  assert.ok(rules.includes('.sb-fg--text-primary{color:var(--sb-text-primary)}'), rules.join('\n'));
  assert.ok(rules.includes('.sb-rad--radius-md{border-radius:var(--sb-radius-md)}'));
});

test('managed classes are the ones this file writes and nothing a theme or author adds', () => {
  for (const c of ['sb-fg--text-primary', 'sb-font-bold', 'sb-uppercase', 'sb-normal-case', 'sb-radius-lg', 'sb-border-solid', 'sb-shadow-md', 'sb-ty-size', 'sb-x-0123456789abcdef']) assert.ok(MANAGED_CLASS.test(c), c);
  for (const c of ['sb-block', 'sb-block--core-heading', 'sb-align-center', 'sbx-selected', 'my-class', 'sb-heading', 'sb-hide-tablet']) assert.ok(!MANAGED_CLASS.test(c), c);
});

test('a top-level Container: background and padding are predicted, an image is left to the server', () => {
  const ok = compileBlockStyle({ type: 'core.container', style: { background: { color: '#112233', gradient: 'linear-gradient(#000,#fff)' }, padding: { top: '4rem', bottom: '2rem' } } });
  assert.equal(ok.covered, true);
  assert.deepEqual(ok.scoped, ['padding-top:4rem', 'padding-bottom:2rem']);
  assert.deepEqual(ok.inline, ['background-color:#112233', 'background-image:linear-gradient(#000,#fff)']);
  assert.equal(compileBlockStyle({ style: { background: { image: { media_id: 3 } } } }).covered, false);
  assert.equal(compileBlockStyle({ style: {} }).covered, true);
});

test('a Container border paints on the canvas as a block\'s does (the fixture holds what the server writes)', () => {
  const border = { style: 'solid', width: '1px', color: 'color.accent', radius: 'md', bottom: { width: '4px', color: '#123456' }, radius_corners: { tl: '0' } };
  const out = compileBlockStyle({ type: 'core.container', style: { border } });
  assert.equal(out.covered, true, 'no repaint from the server is needed');
  assert.deepEqual(out.scoped, ['border-bottom-width:4px', 'border-bottom-color:#123456', 'border-top-left-radius:0']);
  assert.deepEqual(out.inline, ['border-width:1px']);
  assert.deepEqual(sorted(out.classes), ['sb-border-solid', 'sb-radius-md']);
  assert.equal(compileBlockStyle({ style: { border: { top: { width: '2px' } } } }).scoped.join(';'), 'border-top-width:2px');
});

// The strings tests/unit/StudioBuilderContainerUpgradeTest.php asserts for StyleSurface, word for word.
test('boxed output matches the server: the content box, the gutter inside it, and full size', () => {
  const boxed = compileBlockStyle({ style: { layout: { content_width: 'boxed', gutter: 'site', content_size: 'wide' }, padding: { left: '40px' } } }).scoped;
  assert.ok(boxed.includes('--sb-box:var(--sb-layout-wide,80rem)'), boxed.join(';'));
  assert.ok(boxed.includes('padding-left:calc(40px + var(--sb-type-section_gutter,1rem) + max(0px,(100% - 40px - 0px - var(--sb-box))/2))'), boxed.join(';'));
  assert.ok(boxed.includes('padding-right:calc(0px + var(--sb-type-section_gutter,1rem) + max(0px,(100% - 40px - 0px - var(--sb-box))/2))'), boxed.join(';'));
  assert.ok(compileBlockStyle({ style: { layout: { content_width: 'boxed', gutter: 'site', content_size: 'full' } } }).scoped.includes('--sb-box:100%'));
});

test('spacing output matches the server: the site scale, per device, and own padding wins', () => {
  const css = compileBlockStyle({ style: { layout: { padding_y: 'lg' }, padding: { top: '5px' } } }).scoped;
  const scale = css.indexOf('padding-top:calc(var(--sb-space-lg,3rem)*4/3)');
  assert.ok(scale >= 0 && scale < css.indexOf('padding-top:5px'), 'padding comes later, so it wins');
  const device = compileBlockStyle({ style: {}, responsive: { mobile: { style: { layout: { padding_y: 'md', content_max: '90%' } } } } }).media.flatMap((m) => m.declarations);
  assert.ok(device.includes('padding-top:calc(var(--sb-space-md,1.5rem)*4/3) !important'), device.join(';'));
  assert.ok(device.includes('--sb-box:90% !important'), device.join(';'));
});
