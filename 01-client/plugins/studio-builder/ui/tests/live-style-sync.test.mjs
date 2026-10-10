// The canvas shows a style edit before the server has rendered it, and gives way to the server's render afterwards.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { isStructuralChange } from '../src/core/canvasLiveSync.mjs';
import { releaseLive, styleChangeIsLive, syncLiveStyles } from '../src/core/liveStyleSync.mjs';

/** Just enough of a canvas document for the sync: elements with classes, attributes and a head. */
class FakeEl {
  constructor(doc, tag, attrs = {}) {
    this.ownerDocument = doc; this.tagName = tag; this.attrs = new Map(Object.entries(attrs)); this.text = '';
    const self = this;
    this.classList = {
      [Symbol.iterator]() { return self.cls().values(); },
      remove(c) { self.setCls(self.cls().filter((x) => x !== c)); },
      add(c) { const l = self.cls(); if (!l.includes(c)) self.setCls([...l, c]); },
      contains(c) { return self.cls().includes(c); },
    };
    Object.defineProperty(this.classList, 'length', { get: () => self.cls().length });
  }
  cls() { return (this.attrs.get('class') || '').split(/\s+/).filter(Boolean); }
  setCls(l) { this.attrs.set('class', l.join(' ')); }
  getAttribute(n) { return this.attrs.has(n) ? this.attrs.get(n) : null; }
  setAttribute(n, v) { this.attrs.set(n, String(v)); }
  removeAttribute(n) { this.attrs.delete(n); }
  get id() { return this.getAttribute('id') || ''; }
  set id(v) { this.setAttribute('id', v); }
  get textContent() { return this.text; }
  set textContent(v) { this.text = v; }
}
class FakeDoc {
  constructor() { this.nodes = []; this.head = { appendChild: (e) => { this.nodes.push(e); } }; }
  add(id, attrs = {}) { const e = new FakeEl(this, 'div', { 'data-sb-node': id, ...attrs }); this.nodes.push(e); return e; }
  createElement(tag) { return new FakeEl(this, tag); }
  getElementById(id) { return this.nodes.find((n) => n.id === id) || null; }
  querySelector(sel) { const m = /^\[data-sb-node="([\w-]+)"\]$/.exec(sel); return m ? this.nodes.find((n) => n.getAttribute('data-sb-node') === m[1]) || null : null; }
}

const tokens = { has: (ref) => ['text.primary', 'surface.accent'].includes(ref) };
const block = (style = {}, extra = {}) => ({ id: 'blk_aaaaaaaaaaaaaaaaaaaaaaaa', type: 'core.heading', props: { text: 'x' }, style, children: [], ...extra });
const docOf = (b, section = {}) => ({ sections: [{ id: 'sec_aaaaaaaaaaaaaaaaaaaaaaaa', style: {}, blocks: [b], ...section }] });

test('a style edit the client can predict is not a structural change, one it cannot is', () => {
  const before = docOf(block({ padding: { top: '1rem' } }));
  assert.equal(isStructuralChange(before, docOf(block({ padding: { top: '2rem' } })), tokens), false);
  assert.equal(isStructuralChange(before, docOf(block({ padding: { top: '2rem' } })), null), true, 'without a token source it is as before');
  assert.equal(isStructuralChange(before, docOf(block({ padding: { top: '1rem' }, text_token: 'text.nope' })), tokens), true, 'a token the theme lacks is the server\'s');
  assert.equal(isStructuralChange(before, docOf(block({ padding: { top: '2rem' } }, { style_states: { hover: { opacity: 0.5 } } })), tokens), true, 'states are the server\'s');
  assert.equal(isStructuralChange(before, docOf(block({ background: { image: { media_id: 3 } } })), tokens), true, 'a background image is the server\'s');
  assert.equal(isStructuralChange(docOf(block()), { sections: [] }, tokens), true, 'removing a section is still structural');
});

test('a per-device override and a section background are live too', () => {
  const a = docOf(block());
  const b = docOf(block({}, { responsive: { tablet: { style: { typography: { size: '22px' } } } } }));
  assert.equal(isStructuralChange(a, b, tokens), false);
  const sec = isStructuralChange(docOf(block(), { style: {} }), docOf(block(), { style: { background: { color: '#112233' } } }), tokens);
  assert.equal(sec, false);
});

test('styleChangeIsLive: both sides must be predictable and of one kind', () => {
  assert.equal(styleChangeIsLive({ kind: 'block', node: block() }, { kind: 'block', node: block({ opacity: 0.5 }) }, tokens), true);
  assert.equal(styleChangeIsLive({ kind: 'block', node: block() }, { kind: 'section', node: {} }, tokens), false);
  assert.equal(styleChangeIsLive(null, { kind: 'block', node: block() }, tokens), false);
});

test('an edit paints the node at once: classes swapped, inline style rebuilt, scoped rule written, motion kept', () => {
  const canvas = new FakeDoc();
  const el = canvas.add('blk_aaaaaaaaaaaaaaaaaaaaaaaa', { class: 'sb-block sb-x-0123456789abcdef sb-font-bold sbx-selected', style: '--sb-anim-duration:300ms;text-align:center;opacity:0.9' });
  const server = docOf(block({ typography: { weight: 'bold' }, opacity: 0.9 }));
  const working = docOf(block({ typography: { weight: 'normal', size: '2rem' }, padding: { top: '3rem' }, text_token: 'text.primary' }));
  const live = new Map();
  assert.equal(syncLiveStyles(canvas, server, working, tokens, live), 1);
  assert.deepEqual(el.cls().sort(), ['sb-block', 'sb-font-normal', 'sb-fg--text-primary', 'sb-ty-fw', 'sb-ty-size', 'sbx-selected'].sort());
  assert.equal(el.getAttribute('style'), '--sb-anim-duration:300ms;text-align:center;font-size:2rem');
  const sheet = canvas.getElementById('sbx-live-style');
  assert.match(sheet.textContent, /\[data-sb-node="blk_aaaaaaaaaaaaaaaaaaaaaaaa"\]\[data-sb-node\]\{padding-top:3rem\}/);
  assert.match(sheet.textContent, /\.sb-fg--text-primary\{color:var\(--sb-text-primary\)\}/);
  assert.equal(sheet.getAttribute('data-sbx-owned'), '1', 'the morph must leave it alone');
  assert.ok(live.has('blk_aaaaaaaaaaaaaaaaaaaaaaaa'));
});

test('nothing differs from the server: nothing is painted, no sheet is made', () => {
  const canvas = new FakeDoc();
  canvas.add('blk_aaaaaaaaaaaaaaaaaaaaaaaa', { class: 'sb-block' });
  const doc = docOf(block({ opacity: 0.5 }));
  assert.equal(syncLiveStyles(canvas, doc, doc, tokens, new Map()), 0);
  assert.equal(canvas.getElementById('sbx-live-style'), null);
});

test('a node stays painted after the edit is undone, until the server render arrives, then every override ends', () => {
  const canvas = new FakeDoc();
  canvas.add('blk_aaaaaaaaaaaaaaaaaaaaaaaa', { class: 'sb-block sb-x-0123456789abcdef' });
  const server = docOf(block({ padding: { top: '1rem' } }));
  const live = new Map();
  syncLiveStyles(canvas, server, docOf(block({ padding: { top: '4rem' } })), tokens, live);
  assert.match(canvas.getElementById('sbx-live-style').textContent, /padding-top:4rem/);
  // undo: the working document equals the server's again, but the server's class was removed from the element
  assert.equal(syncLiveStyles(canvas, server, server, tokens, live), 1);
  assert.match(canvas.getElementById('sbx-live-style').textContent, /padding-top:1rem/);
  releaseLive(canvas, live);
  assert.equal(canvas.getElementById('sbx-live-style').textContent, '');
  assert.equal(live.size, 0);
});

test('a style the client cannot predict leaves the element and the sheet as they were', () => {
  const canvas = new FakeDoc();
  const el = canvas.add('blk_aaaaaaaaaaaaaaaaaaaaaaaa', { class: 'sb-block sb-x-0123456789abcdef' });
  const server = docOf(block());
  assert.equal(syncLiveStyles(canvas, server, docOf(block({ text_token: 'text.nope' })), tokens, new Map()), 0);
  assert.ok(el.classList.contains('sb-x-0123456789abcdef'));
  assert.equal(canvas.getElementById('sbx-live-style'), null);
});

test('a section keeps its surface class and only gives up its scoped rule class', () => {
  const canvas = new FakeDoc();
  const el = canvas.add('sec_aaaaaaaaaaaaaaaaaaaaaaaa', { class: 'sb-section sb-bg--surface-primary sb-x-0123456789abcdef' });
  const server = docOf(block(), { style: {} });
  const working = docOf(block(), { style: { background: { color: '#102030' }, padding: { top: '5rem' } } });
  assert.equal(syncLiveStyles(canvas, server, working, tokens, new Map()), 1);
  assert.deepEqual(el.cls().sort(), ['sb-bg--surface-primary', 'sb-section']);
  assert.match(canvas.getElementById('sbx-live-style').textContent, /background-color:#102030;padding-top:5rem/);
});
