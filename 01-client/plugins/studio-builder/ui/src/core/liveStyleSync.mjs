// Paints an author's style edits onto the open canvas at once, from `core/liveStyle.mjs`, and hands the canvas back
// to the server's render when that arrives. The server stays the source of truth: a node is only ever overridden here
// while the document differs from what the server last painted, and `releaseLive` ends every override.

import { MANAGED_CLASS, compileBlockStyle, compileSectionStyle, ruleText, tokenClassRules } from './liveStyle.mjs';

const SHEET_ID = 'sbx-live-style';
const KEPT_INLINE = /^(--|text-align\s*:)/;

const same = (a, b) => a === b || JSON.stringify(a === undefined ? null : a) === JSON.stringify(b === undefined ? null : b);
const esc = (id) => String(id).replace(/[^\w-]/g, '');

/** Which theme tokens exist, read from what the page itself defines (the server writes one custom property per token). */
export function tokensOfDoc(doc) {
  let style = null;
  try { style = doc && doc.defaultView ? doc.defaultView.getComputedStyle(doc.documentElement) : null; } catch { style = null; }
  return {
    has(ref) {
      if (!style || typeof ref !== 'string') return false;
      return style.getPropertyValue(`--sb-${ref.replace(/\./g, '-')}`).trim() !== '';
    },
  };
}

/** Every section and block of a document, by id. */
function indexNodes(doc) {
  const map = new Map();
  const walk = (blocks) => {
    for (const b of Array.isArray(blocks) ? blocks : []) {
      if (!b || !b.id) continue;
      map.set(b.id, { kind: 'block', node: b });
      walk(b.children);
    }
  };
  for (const s of Array.isArray(doc && doc.sections) ? doc.sections : []) {
    if (!s || !s.id) continue;
    map.set(s.id, { kind: 'section', node: s });
    walk(s.blocks);
  }
  return map;
}

/** The part of a node the style compiler reads. */
function styleKey(entry) {
  const n = entry.node;
  return entry.kind === 'section' ? [n.style] : [n.style, n.responsive, n.style_states];
}

/** Can the client predict this node exactly, before and after? When not, the server's render has to show it. */
export function styleChangeIsLive(prevEntry, nextEntry, tokens) {
  if (!prevEntry || !nextEntry || prevEntry.kind !== nextEntry.kind) return false;
  const compile = (e) => (e.kind === 'section' ? compileSectionStyle(e.node) : compileBlockStyle(e.node, tokens));
  return compile(prevEntry).covered && compile(nextEntry).covered;
}

function sheetOf(doc) {
  let el = doc.getElementById(SHEET_ID);
  if (!el) {
    el = doc.createElement('style');
    el.id = SHEET_ID;
    el.setAttribute('data-sbx-owned', '1');
    doc.head.appendChild(el);
  }
  return el;
}

function applyToElement(el, compiled, isSection) {
  if (isSection) {
    // A section keeps its surface and padding token classes; only its own scoped class gives way to the live rule.
    for (const cls of Array.from(el.classList)) if (/^sb-x-[0-9a-f]{16}$/.test(cls)) el.classList.remove(cls);
    return;
  }
  const keep = (el.getAttribute('style') || '').split(';').map((d) => d.trim()).filter((d) => d && KEPT_INLINE.test(d));
  for (const cls of Array.from(el.classList)) if (MANAGED_CLASS.test(cls)) el.classList.remove(cls);
  for (const cls of compiled.classes) el.classList.add(cls);
  const inline = [...keep, ...compiled.inline].join(';');
  if (inline) el.setAttribute('style', inline); else el.removeAttribute('style');
}

/**
 * Paint every style difference between what the server last painted (`serverDoc`) and the working document.
 * `live` maps each node id this function has overridden to the CSS it wrote; it grows here and is emptied by `releaseLive`.
 * A node that stops being predictable keeps its last live paint until the server's render replaces it.
 * Returns the number of nodes it painted.
 */
export function syncLiveStyles(canvasDoc, serverDoc, workingDoc, tokens, live) {
  if (!canvasDoc || !canvasDoc.head || !serverDoc || !workingDoc) return 0;
  const server = indexNodes(serverDoc);
  const working = indexNodes(workingDoc);
  let painted = 0;

  for (const [id, entry] of working) {
    const before = server.get(id);
    if (!before) continue; // a new node is a structural change: the server paints it
    const differs = !same(styleKey(before), styleKey(entry));
    if (!differs && !live.has(id)) continue;
    if (!styleChangeIsLive(before, entry, tokens)) continue;

    const el = canvasDoc.querySelector(`[data-sb-node="${esc(id)}"]`);
    if (!el) continue;
    const isSection = entry.kind === 'section';
    const compiled = isSection ? { ...compileSectionStyle(entry.node), classes: [], inline: [], media: [] } : compileBlockStyle(entry.node, tokens);
    applyToElement(el, compiled, isSection);
    live.set(id, { rule: ruleText(id, compiled), tokenRules: tokenClassRules(compiled.classes) });
    painted += 1;
  }

  const tokenRules = new Set();
  const rules = [];
  for (const { rule, tokenRules: tr } of live.values()) {
    if (rule) rules.push(rule);
    for (const r of tr) tokenRules.add(r);
  }
  const css = [...tokenRules, ...rules].join('\n');
  const sheet = canvasDoc.getElementById(SHEET_ID);
  if (css || sheet) {
    const el = sheetOf(canvasDoc);
    if (el.textContent !== css) el.textContent = css;
  }
  return painted;
}

/** The server's render has been merged in: end every override (the render already carries the same styles). */
export function releaseLive(canvasDoc, live) {
  live.clear();
  const sheet = canvasDoc && canvasDoc.getElementById ? canvasDoc.getElementById(SHEET_ID) : null;
  if (sheet && sheet.textContent !== '') sheet.textContent = '';
}

export const LIVE_SHEET_ID = SHEET_ID;
