// Widget design-control audit — step 2 of 2: open each rendered widget page in Chromium and check that every style probe
// actually changes what a visitor sees. Run after `php tests/audit/render-widgets.php <dir>`:
//
//   node audit/measure.mjs <dir>        -> <dir>/results.json and <dir>/results.md
//
// A probe PASSES when the computed style that the control promises is what the browser shows, FAILS when it is not (with
// the measured values as evidence), and is N/A when the widget has nothing the control could apply to (no visible text for
// a font control, say). "covered" flags a colour that reaches the wrapper but is hidden behind an opaque inner element.
import { chromium } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const dir = path.resolve(process.argv[2] || '/tmp/fh-audit');
const index = JSON.parse(fs.readFileSync(path.join(dir, 'index.json'), 'utf8'));
const probes = ['base', ...index.probes];

/** Runs inside the page: measure every probe section of one widget file. */
function measureInPage(probeNames) {
  const rgb = (c) => { const d = document.createElement('i'); d.style.color = c; document.body.appendChild(d); const v = getComputedStyle(d).color; d.remove(); return v; };
  const visible = (el) => { const r = el.getBoundingClientRect(); const cs = getComputedStyle(el); return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none' && Number(cs.opacity) > 0; };
  const hasText = (el) => Array.from(el.childNodes).some((n) => n.nodeType === 3 && n.textContent.trim() !== '');
  const textTarget = (wrap) => {
    const all = [wrap, ...wrap.querySelectorAll('*')];
    return all.find((el) => hasText(el) && visible(el) && !['SCRIPT', 'STYLE', 'OPTION'].includes(el.tagName)) || null;
  };
  const sections = Array.from(document.querySelectorAll('main > section'));
  const out = {};
  sections.forEach((section, i) => {
    const wrap = section.querySelector('.sb-block');
    const name = probeNames[i];
    if (!wrap) { out[name] = { missing: true }; return; }
    const cs = getComputedStyle(wrap);
    const text = textTarget(wrap);
    const tcs = text ? getComputedStyle(text) : null;
    const rect = wrap.getBoundingClientRect();
    // Does one opaque child cover (nearly) all of the wrapper? Then a colour on the wrapper is not what the visitor sees.
    let covered = false;
    for (const child of wrap.children) {
      const r = child.getBoundingClientRect();
      const bg = getComputedStyle(child).backgroundColor;
      const opaque = bg && !/rgba?\(\s*0,\s*0,\s*0,\s*0\)|transparent/.test(bg);
      if (opaque && r.width >= rect.width * 0.95 && r.height >= rect.height * 0.9) covered = true;
    }
    out[name] = {
      visible: rect.width > 0 && rect.height > 0,
      w: Math.round(rect.width), h: Math.round(rect.height),
      bg: cs.backgroundColor, bgImage: cs.backgroundImage, color: cs.color,
      pad: [cs.paddingTop, cs.paddingRight, cs.paddingBottom, cs.paddingLeft],
      mt: cs.marginTop, mb: cs.marginBottom,
      bw: cs.borderTopWidth, bwr: cs.borderRightWidth, bbw: cs.borderBottomWidth, blw: cs.borderLeftWidth, bstyle: cs.borderTopStyle,
      radius: cs.borderTopLeftRadius, shadow: cs.boxShadow, opacity: cs.opacity, transform: cs.transform, filter: cs.filter,
      textTag: text ? text.tagName.toLowerCase() : null,
      t: tcs ? { color: tcs.color, size: tcs.fontSize, weight: tcs.fontWeight, style: tcs.fontStyle, tt: tcs.textTransform, ls: tcs.letterSpacing, lh: tcs.lineHeight, ff: tcs.fontFamily, td: tcs.textDecorationLine, align: tcs.textAlign } : null,
      wdeco: cs.textDecorationLine, bcolor: cs.borderTopColor, ffw: cs.fontFamily,
      covered,
      expect: {
        red: rgb('#ff0000'), green: rgb('#00aa00'),
        textPrimary: rgb(getComputedStyle(document.documentElement).getPropertyValue('--sb-text-primary').trim() || '#000'),
        borderDefault: rgb(getComputedStyle(document.documentElement).getPropertyValue('--sb-border-default').trim() || '#000'),
        fontHeading: (getComputedStyle(document.documentElement).getPropertyValue('--sb-font-heading').split(',')[0] || '').trim().replace(/["']/g, '').toLowerCase(),
        surfaceAccent: rgb(getComputedStyle(document.documentElement).getPropertyValue('--sb-surface-accent').trim() || '#000'),
      },
    };
  });
  return out;
}

/** The pass/fail rule of each probe, given the probe's measurement `m` and the base measurement `b`. */
const RULES = {
  text_color:     (m, b) => m.t ? [m.t.color === m.expect.red, `text ${m.t.color} on <${m.textTag}>`] : null,
  text_token:     (m, b) => m.t ? [m.t.color === m.expect.textPrimary && m.t.color !== b.t?.color || m.t.color === m.expect.textPrimary, `text ${m.t.color}, wanted ${m.expect.textPrimary}`] : null,
  bg_color:       (m) => [m.bg === m.expect.green, `wrapper background ${m.bg}`, m.covered ? 'covered' : null],
  bg_token:       (m) => [m.bg === m.expect.surfaceAccent, `wrapper background ${m.bg}, wanted ${m.expect.surfaceAccent}`, m.covered ? 'covered' : null],
  bg_gradient:    (m) => [/linear-gradient/.test(m.bgImage), `wrapper background-image ${String(m.bgImage).slice(0, 40)}`, m.covered ? 'covered' : null],
  padding:        (m) => [m.pad.every((p) => p === '40px'), `padding ${m.pad.join(' ')}`],
  margin:         (m) => [m.mt === '30px' && m.mb === '30px', `margin ${m.mt} / ${m.mb}`],
  border:         (m) => [[m.bw, m.bwr, m.bbw, m.blw].every((x) => x === '4px') && m.bstyle === 'solid', `border ${m.bw} ${m.bstyle}`],
  radius:         (m) => [m.radius === '24px', `radius ${m.radius}`],
  shadow:         (m) => [m.shadow && m.shadow !== 'none', `shadow ${String(m.shadow).slice(0, 40)}`],
  font_size:      (m) => m.t ? [parseFloat(m.t.size) >= 40, `font-size ${m.t.size} on <${m.textTag}>`] : null,
  font_weight:    (m) => m.t ? [Number(m.t.weight) >= 700, `weight ${m.t.weight} on <${m.textTag}>`] : null,
  font_italic:    (m) => m.t ? [m.t.style === 'italic', `style ${m.t.style} on <${m.textTag}>`] : null,
  uppercase:      (m) => m.t ? [m.t.tt === 'uppercase', `transform ${m.t.tt} on <${m.textTag}>`] : null,
  letter_spacing: (m) => m.t ? [m.t.ls !== 'normal' && parseFloat(m.t.ls) > 0, `letter-spacing ${m.t.ls} on <${m.textTag}>`] : null,
  line_height:    (m) => m.t ? [Math.abs(parseFloat(m.t.lh) / parseFloat(m.t.size) - 2.4) < 0.05, `line-height ${m.t.lh} / ${m.t.size} on <${m.textTag}>`] : null,
  font_family:    (m) => m.t ? [/georgia/i.test(m.t.ff), `font-family ${m.t.ff.slice(0, 40)} on <${m.textTag}>`] : null,
  underline:      (m) => m.t ? [/underline/.test(m.wdeco), `decoration ${m.wdeco} on the block`] : null,
  align_center:   (m) => m.t ? [m.t.align === 'center', `text-align ${m.t.align} on <${m.textTag}>`] : null,
  opacity:        (m) => [Math.abs(Number(m.opacity) - 0.5) < 0.01, `opacity ${m.opacity}`],
  max_width:      (m) => [m.w <= 201, `width ${m.w}px`],
  min_height:     (m) => [m.h >= 299, `height ${m.h}px`],
  rotate:         (m) => [m.transform && m.transform !== 'none', `transform ${m.transform}`],
  blur:           (m) => [/blur/.test(m.filter), `filter ${m.filter}`],
  space_token:    (m) => [m.pad.every((p) => p === '48px'), `padding ${m.pad.join(' ')}, wanted 48px (space.lg)`],
  radius_token:   (m) => [m.radius === '16px', `radius ${m.radius}, wanted 16px (radius.lg)`],
  shadow_token:   (m) => [m.shadow && m.shadow !== 'none', `shadow ${String(m.shadow).slice(0, 40)}`],
  font_token:     (m) => m.t ? [m.t.ff.toLowerCase().includes(m.expect.fontHeading), `font-family ${m.t.ff.slice(0, 40)} on <${m.textTag}>`] : null,
  border_token:   (m) => [m.bcolor === m.expect.borderDefault, `border colour ${m.bcolor}, wanted ${m.expect.borderDefault}`],
};

const browser = await chromium.launch();
const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
const results = {};
for (const type of Object.keys(index.widgets)) {
  const file = path.join(dir, `${type}.html`);
  if (!fs.existsSync(file)) { results[type] = { error: index.widgets[type].error || 'not rendered' }; continue; }
  await page.goto(pathToFileURL(file).href);
  const consoleErrors = [];
  const m = await page.evaluate(measureInPage, probes);
  const base = m.base;
  const row = { base: base && !base.missing ? { visible: base.visible, w: base.w, h: base.h, hasText: !!base.t, covered: base.covered } : { missing: true }, probes: {} };
  for (const name of index.probes) {
    const probe = m[name];
    if (!probe || probe.missing || !base || base.missing) { row.probes[name] = { r: 'error', why: 'no wrapper' }; continue; }
    const res = RULES[name] ? RULES[name](probe, base) : null;
    if (res === null) row.probes[name] = { r: 'na', why: 'no visible text' };
    else row.probes[name] = { r: res[0] ? (res[2] ? 'covered' : 'pass') : 'fail', why: res[1] };
  }
  results[type] = row;
}
await browser.close();
fs.writeFileSync(path.join(dir, 'results.json'), JSON.stringify(results, null, 1));

// Markdown matrix.
const sym = { pass: '✓', fail: '✗', na: '–', covered: '◐', error: '!' };
let md = '| widget | ' + index.probes.join(' | ') + ' |\n|---|' + index.probes.map(() => ':-:').join('|') + '|\n';
for (const [type, row] of Object.entries(results)) {
  if (row.error) { md += `| ${type} | ${row.error} |\n`; continue; }
  md += `| ${type} | ` + index.probes.map((p) => sym[row.probes[p].r]).join(' | ') + ' |\n';
}
fs.writeFileSync(path.join(dir, 'results.md'), md);
const tally = {};
for (const row of Object.values(results)) for (const [p, v] of Object.entries(row.probes || {})) { tally[p] ??= { pass: 0, fail: 0, na: 0, covered: 0, error: 0 }; tally[p][v.r]++; }
console.log(JSON.stringify(tally, null, 0));
