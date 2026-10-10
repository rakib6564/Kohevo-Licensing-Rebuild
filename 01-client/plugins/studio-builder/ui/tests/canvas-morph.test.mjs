// The canvas repaints from the server by morphing the render into the open frame. The properties that matter: the
// document is NOT replaced (elements keep their identity), editor-owned classes and attributes survive, a changed
// node id or order is followed, and a stylesheet swap leaves the editor's own style tags alone.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { isEditorAttr, isInlineEditing, mergedClass, morphChildren, morphDocument } from '../src/core/canvasMorph.mjs';

/** A small DOM: only what the morph touches. */
class Node_ {
  constructor(doc, nodeType) { this.ownerDocument = doc; this.nodeType = nodeType; this.parentNode = null; this.childNodes = []; }
  get firstChild() { return this.childNodes[0] || null; }
  get nextSibling() { if (!this.parentNode) return null; const s = this.parentNode.childNodes; return s[s.indexOf(this) + 1] || null; }
  insertBefore(n, ref) { if (n.parentNode) n.parentNode.removeChild(n); const i = ref ? this.childNodes.indexOf(ref) : this.childNodes.length; this.childNodes.splice(i, 0, n); n.parentNode = this; return n; }
  appendChild(n) { return this.insertBefore(n, null); }
  removeChild(n) { this.childNodes.splice(this.childNodes.indexOf(n), 1); n.parentNode = null; return n; }
  remove() { if (this.parentNode) this.parentNode.removeChild(this); }
}
class El extends Node_ {
  constructor(doc, tag, attrs = {}) { super(doc, 1); this.tagName = tag.toUpperCase(); this.attrs = new Map(Object.entries(attrs)); }
  get attributes() { return [...this.attrs].map(([name, value]) => ({ name, value })); }
  get children() { return this.childNodes.filter((n) => n.nodeType === 1); }
  getAttribute(n) { return this.attrs.has(n) ? this.attrs.get(n) : null; }
  setAttribute(n, v) { this.attrs.set(n, String(v)); }
  removeAttribute(n) { this.attrs.delete(n); }
  hasAttribute(n) { return this.attrs.has(n); }
  get id() { return this.getAttribute('id') || ''; }
  get textContent() { return this.childNodes.map((c) => (c.nodeType === 3 ? c.nodeValue : c.textContent)).join(''); }
  set textContent(v) { this.childNodes = []; this.appendChild(new Text_(this.ownerDocument, v)); }
  querySelector(sel) { const cls = sel.replace(/^\./, ''); const walk = (e) => { for (const c of e.children) { if ((c.getAttribute('class') || '').split(/\s+/).includes(cls)) return c; const r = walk(c); if (r) return r; } return null; }; return walk(this); }
}
class Text_ extends Node_ { constructor(doc, v) { super(doc, 3); this.nodeValue = v; } }
class Doc {
  constructor() { this.head = new El(this, 'head'); this.body = new El(this, 'body'); }
  importNode(n) {
    if (n.nodeType === 3) return new Text_(this, n.nodeValue);
    const e = new El(this, n.tagName, Object.fromEntries(n.attrs));
    n.childNodes.forEach((c) => e.appendChild(this.importNode(c)));
    return e;
  }
}
/** h('div', {id}, ...kids) builds a tree in `doc`; a string is a text node. */
const h = (doc, tag, attrs = {}, ...kids) => { const e = new El(doc, tag, attrs); kids.forEach((k) => e.appendChild(typeof k === 'string' ? new Text_(doc, k) : k)); return e; };

test('mergedClass keeps the editor\'s sbx-* classes and takes the server\'s for the rest', () => {
  assert.equal(mergedClass('sb-a sbx-selected', 'sb-a sb-b'), 'sb-a sb-b sbx-selected');
  assert.equal(mergedClass('sb-old sbx-hover', 'sb-new'), 'sb-new sbx-hover');
  assert.equal(mergedClass('', 'x'), 'x');
  assert.equal(mergedClass('sbx-selected', ''), 'sbx-selected');
  assert.equal(mergedClass('sbx-x', 'sbx-x sb-y'), 'sbx-x sb-y', 'no duplicate when the server writes it too');
});

test('editor attributes are recognised', () => {
  assert.ok(isEditorAttr('contenteditable') && isEditorAttr('spellcheck') && isEditorAttr('data-sbx-anything'));
  assert.ok(!isEditorAttr('class') && !isEditorAttr('data-sb-node') && !isEditorAttr('style'));
});

test('morphing updates in place: the same elements, new class names and text, editor classes kept', () => {
  const live = new Doc(); const next = new Doc();
  const a = h(live, 'section', { 'data-sb-node': 'sec_1', class: 'sb-section s-old sbx-selected', contenteditable: 'true' }, h(live, 'h2', { 'data-sb-node': 'blk_1', class: 'h-old' }, 'Old'));
  live.body.appendChild(a);
  next.body.appendChild(h(next, 'section', { 'data-sb-node': 'sec_1', class: 'sb-section s-new', 'data-extra': '1' }, h(next, 'h2', { 'data-sb-node': 'blk_1', class: 'h-new' }, 'New')));
  const heading = a.children[0];
  morphChildren(live.body, next.body);
  assert.equal(live.body.children[0], a, 'same section element');
  assert.equal(a.children[0], heading, 'same heading element');
  assert.equal(a.getAttribute('class'), 'sb-section s-new sbx-selected');
  assert.equal(a.getAttribute('contenteditable'), 'true', 'the editor attribute stays');
  assert.equal(a.getAttribute('data-extra'), '1');
  assert.equal(heading.getAttribute('class'), 'h-new');
  assert.equal(heading.textContent, 'New');
});

test('morphing follows an inserted, removed and moved node by its id', () => {
  const live = new Doc(); const next = new Doc();
  const mk = (d, id) => h(d, 'div', { 'data-sb-node': id }, id);
  ['a', 'b', 'c'].forEach((id) => live.body.appendChild(mk(live, id)));
  const [a, b, c] = live.body.children;
  ['c', 'a', 'x'].forEach((id) => next.body.appendChild(mk(next, id)));
  morphChildren(live.body, next.body);
  const ids = live.body.children.map((e) => e.getAttribute('data-sb-node'));
  assert.deepEqual(ids, ['c', 'a', 'x']);
  assert.equal(live.body.children[0], c, 'c moved, not rebuilt');
  assert.equal(live.body.children[1], a, 'a kept');
  assert.equal(b.parentNode, null, 'b removed');
});

test('a document morph swaps the compiled stylesheet and the site CSS, keeps the editor\'s style tags, adds font links', () => {
  const live = new Doc(); const next = new Doc();
  live.head.appendChild(h(live, 'style', {}, '.a{color:red}'));
  live.head.appendChild(h(live, 'style', { 'data-sb': 'tenant-css' }, '.t{x:1}'));
  live.head.appendChild(h(live, 'style', { id: 'sbx-canvas-overlay' }, 'EDITOR'));
  next.head.appendChild(h(next, 'style', {}, '.a{color:blue}'));
  next.head.appendChild(h(next, 'link', { rel: 'stylesheet', href: 'https://fonts.googleapis.com/css2?family=Inter' }));
  live.body.appendChild(h(live, 'p', { class: 'old' }, 'x'));
  next.body.appendChild(h(next, 'p', { class: 'new' }, 'x'));
  next.body.setAttribute('class', 'sb-body sb-body--editor');
  const p = live.body.children[0];
  morphDocument(live, next);
  const styles = live.head.children.filter((e) => e.tagName === 'STYLE');
  assert.equal(styles[0].textContent, '.a{color:blue}');
  assert.ok(!styles.some((s) => s.getAttribute('data-sb') === 'tenant-css'), 'the emptied Custom CSS is gone');
  assert.equal(styles.find((s) => s.id === 'sbx-canvas-overlay').textContent, 'EDITOR', 'the editor stylesheet is untouched');
  assert.equal(live.head.children.filter((e) => e.tagName === 'LINK').length, 1);
  assert.equal(live.body.children[0], p);
  assert.equal(p.getAttribute('class'), 'new');
  assert.equal(live.body.getAttribute('class'), 'sb-body sb-body--editor');
  morphDocument(live, next); // idempotent: no duplicate link
  assert.equal(live.head.children.filter((e) => e.tagName === 'LINK').length, 1);
});

test('inline editing is detected so the repaint waits', () => {
  const d = new Doc();
  assert.equal(isInlineEditing(d.body), false);
  d.body.appendChild(h(d, 'p', { class: 'sbx-inline-editing' }, 'typing'));
  assert.equal(isInlineEditing(d.body), true);
  assert.equal(isInlineEditing(null), false);
});
