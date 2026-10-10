// The canvas follows an insert, move, duplicate or delete at once; what it cannot do safely it leaves to the server.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { syncLiveStructure } from '../src/core/liveStructure.mjs';

/** A small DOM: only what the structural sync touches. */
class N {
  constructor(doc, type) { this.ownerDocument = doc; this.nodeType = type; this.parentNode = null; this.childNodes = []; }
  insertBefore(n, ref) {
    if (n.parentNode) n.parentNode.childNodes.splice(n.parentNode.childNodes.indexOf(n), 1);
    const i = ref ? this.childNodes.indexOf(ref) : this.childNodes.length;
    this.childNodes.splice(i < 0 ? this.childNodes.length : i, 0, n);
    n.parentNode = this;
    return n;
  }
  appendChild(n) { return this.insertBefore(n, null); }
  removeChild(n) { this.childNodes.splice(this.childNodes.indexOf(n), 1); n.parentNode = null; return n; }
  remove() { if (this.parentNode) this.parentNode.removeChild(this); }
}
class El extends N {
  constructor(doc, tag, attrs = {}) {
    super(doc, 1); this.tagName = tag.toUpperCase(); this.attrs = new Map(Object.entries(attrs)); this.text = '';
    const self = this;
    this.classList = {
      [Symbol.iterator]() { return self.cls().values(); },
      remove(c) { self.attrs.set('class', self.cls().filter((x) => x !== c).join(' ')); },
    };
  }
  cls() { return (this.attrs.get('class') || '').split(/\s+/).filter(Boolean); }
  get attributes() { return [...this.attrs].map(([name, value]) => ({ name, value })); }
  getAttribute(n) { return this.attrs.has(n) ? this.attrs.get(n) : null; }
  setAttribute(n, v) { this.attrs.set(n, String(v)); }
  removeAttribute(n) { this.attrs.delete(n); }
  get children() { return this.childNodes.filter((c) => c.nodeType === 1); }
  set textContent(v) { this.text = v; }
  get textContent() { return this.text + this.children.map((c) => c.textContent).join(''); }
  all() { return this.children.flatMap((c) => [c, ...c.all()]); }
  querySelectorAll(sel) { const m = /^\[([\w-]+)\]$/.exec(sel); return this.all().filter((e) => e.attrs.has(m[1])); }
  querySelector(sel) {
    const m = /^\[([\w-]+)="([\w-]+)"\]$/.exec(sel);
    return this.all().find((e) => e.attrs.get(m[1]) === m[2]) || null;
  }
  closest(sel) { const a = /^\[([\w-]+)\]$/.exec(sel)[1]; let e = this; while (e && e.nodeType === 1) { if (e.attrs.has(a)) return e; e = e.parentNode; } return null; }
  cloneNode(deep) {
    const c = new El(this.ownerDocument, this.tagName, Object.fromEntries(this.attrs)); c.text = this.text;
    if (deep) for (const k of this.childNodes) if (k.nodeType === 1) c.appendChild(k.cloneNode(true));
    return c;
  }
}
class Doc extends El {
  constructor() { super(null, 'doc'); this.ownerDocument = this; }
  createElement(tag) { return new El(this, tag); }
  createComment() { return new N(this, 8); }
}

const blk = (id, extra = {}) => ({ id, type: 'core.text', props: { text: id }, style: {}, children: [], ...extra });
const sec = (id, blocks) => ({ id, style: {}, blocks });
const doc = (...sections) => ({ sections });

/** main > section[sec] > .inner > blocks; a block with children: block > .wrap > blocks. */
function render(d) {
  const root = new Doc();
  const main = root.ownerDocument.createElement('main');
  root.appendChild(main);
  const put = (parent, nodes) => nodes.forEach((n) => {
    const e = root.createElement('div'); e.setAttribute('data-sb-node', n.id); e.setAttribute('data-sb-type', n.type || 'section'); e.setAttribute('class', 'sb-block sbx-selected'); e.textContent = '';
    parent.appendChild(e);
    const kids = n.blocks || n.children || [];
    if (kids.length || n.blocks) { const w = root.createElement('div'); w.setAttribute('class', n.blocks ? 'sb-section__inner' : 'sb-wrap'); e.appendChild(w); put(w, kids); } else e.text = n.id;
  });
  put(main, d.sections);
  return { root, main };
}
const ids = (el) => el.all().filter((e) => e.attrs.has('data-sb-node')).map((e) => e.getAttribute('data-sb-node'));

test('a deleted block disappears with its children, and nothing else moves', () => {
  const before = doc(sec('sec_1', [blk('a'), blk('b', { type: 'layout.container', children: [blk('c')] }), blk('d')]));
  const { root } = render(before);
  const r = syncLiveStructure(root, before, doc(sec('sec_1', [blk('a'), blk('d')])));
  assert.deepEqual(ids(root), ['sec_1', 'a', 'd']);
  assert.equal(r.complete, true);
});

test('a block moved down among its siblings is re-seated, not rebuilt', () => {
  const before = doc(sec('sec_1', [blk('a'), blk('b'), blk('c')]));
  const { root } = render(before);
  const elA = root.querySelector('[data-sb-node="a"]');
  const r = syncLiveStructure(root, before, doc(sec('sec_1', [blk('b'), blk('c'), blk('a')])));
  assert.deepEqual(ids(root), ['sec_1', 'b', 'c', 'a']);
  assert.equal(root.querySelector('[data-sb-node="a"]'), elA, 'the same element');
  assert.equal(r.complete, true);
});

test('a block moved into a container, and out of it', () => {
  const before = doc(sec('sec_1', [blk('a'), blk('box', { type: 'layout.container', children: [blk('x')] })]));
  const { root } = render(before);
  const into = doc(sec('sec_1', [blk('box', { type: 'layout.container', children: [blk('x'), blk('a')] })]));
  assert.equal(syncLiveStructure(root, before, into).complete, true);
  assert.deepEqual(ids(root), ['sec_1', 'box', 'x', 'a']);
  assert.equal(root.querySelector('[data-sb-node="a"]').closest('[data-sb-node]').parentNode.parentNode.getAttribute('data-sb-node'), 'box');
  const out = doc(sec('sec_1', [blk('a'), blk('box', { type: 'layout.container', children: [blk('x')] })]));
  assert.equal(syncLiveStructure(root, into, out).complete, true);
  assert.deepEqual(ids(root), ['sec_1', 'a', 'box', 'x']);
});

test('a section moved above another', () => {
  const before = doc(sec('sec_1', [blk('a')]), sec('sec_2', [blk('b')]));
  const { root, main } = render(before);
  const r = syncLiveStructure(root, before, doc(sec('sec_2', [blk('b')]), sec('sec_1', [blk('a')])));
  assert.deepEqual(main.children.map((e) => e.getAttribute('data-sb-node')), ['sec_2', 'sec_1']);
  assert.equal(r.complete, true);
});

test('a duplicate is a clone of the source with fresh ids and no selection state', () => {
  const box = (id, kid) => blk(id, { type: 'layout.container', props: { gap: 1 }, children: [blk(kid)] });
  const before = doc(sec('sec_1', [box('box', 'x')]));
  const { root } = render(before);
  const copy = { ...box('tmp_box', 'tmp_x') };
  copy.children = [{ ...blk('tmp_x'), props: { text: 'x' } }];
  copy.children[0].props = before.sections[0].blocks[0].children[0].props; // identical content
  const r = syncLiveStructure(root, before, doc(sec('sec_1', [box('box', 'x'), copy])));
  assert.deepEqual(ids(root), ['sec_1', 'box', 'x', 'tmp_box', 'tmp_x']);
  const dup = root.querySelector('[data-sb-node="tmp_box"]');
  assert.ok(!dup.cls().includes('sbx-selected'), 'selection state is not copied');
  assert.ok(dup.cls().includes('sb-block'));
  assert.deepEqual(r.pending, [], 'a clone needs nothing from the server');
  assert.equal(r.complete, true);
});

test('a block with no twin on the canvas gets a placeholder at its place', () => {
  const before = doc(sec('sec_1', [blk('a'), blk('b')]));
  const { root } = render(before);
  const fresh = { id: 'tmp_new', type: 'booking.embed', props: {}, style: {}, children: [] };
  const r = syncLiveStructure(root, before, doc(sec('sec_1', [blk('a'), fresh, blk('b')])));
  assert.deepEqual(ids(root), ['sec_1', 'a', 'tmp_new', 'b']);
  const ph = root.querySelector('[data-sb-node="tmp_new"]');
  assert.ok(ph.cls().includes('sbx-pending'));
  assert.equal(ph.text, 'embed');
  assert.deepEqual(r.pending, [ph], 'its real markup can be fetched');
  assert.equal(r.complete, true);
});

test('a provisional id the server replaced is renamed in place, never rebuilt', () => {
  const tmp = { id: 'tmp_new', type: 'booking.embed', props: {}, style: {}, children: [] };
  const before = doc(sec('sec_1', [blk('a'), tmp]));
  const { root } = render(before);
  const el = root.querySelector('[data-sb-node="tmp_new"]');
  const real = { ...tmp, id: 'blk_real' };
  const r = syncLiveStructure(root, before, doc(sec('sec_1', [blk('a'), real])));
  assert.equal(el.getAttribute('data-sb-node'), 'blk_real');
  assert.equal(root.querySelector('[data-sb-node="blk_real"]'), el);
  assert.deepEqual(ids(root), ['sec_1', 'a', 'blk_real']);
  assert.equal(r.complete, true);
});

test('children that do not share one wrapper are left to the server, and the result says so', () => {
  // two cells, one child each: "tabs" or "columns" markup
  const before = doc(sec('sec_1', [blk('t', { type: 'layout.tabs', children: [blk('p1'), blk('p2')] })]));
  const { root } = render(before);
  const t = root.querySelector('[data-sb-node="t"]');
  const wrap = t.children[0];
  const [p1, p2] = wrap.children;
  const cell1 = root.createElement('div'); const cell2 = root.createElement('div');
  wrap.appendChild(cell1); wrap.appendChild(cell2); cell1.appendChild(p1); cell2.appendChild(p2);
  const r = syncLiveStructure(root, before, doc(sec('sec_1', [blk('t', { type: 'layout.tabs', children: [blk('p2'), blk('p1')] })])));
  assert.equal(r.complete, false);
  assert.equal(p1.parentNode, cell1, 'untouched');
});

test('adding the first child to an empty container is left to the server', () => {
  const before = doc(sec('sec_1', [blk('box', { type: 'layout.container', children: [] })]));
  const { root } = render(before);
  const r = syncLiveStructure(root, before, doc(sec('sec_1', [blk('box', { type: 'layout.container', children: [blk('n', { id: 'tmp_n' })] })])));
  assert.equal(r.complete, false);
});

test('nothing changed: nothing is touched', () => {
  const before = doc(sec('sec_1', [blk('a'), blk('b')]));
  const { root } = render(before);
  assert.deepEqual(syncLiveStructure(root, before, doc(sec('sec_1', [blk('a'), blk('b')]))), { changed: 0, complete: true, pending: [] });
  assert.deepEqual(syncLiveStructure(null, before, before), { changed: 0, complete: false, pending: [] });
});

test('a new section gets one placeholder for the whole section, and its real markup can be fetched', () => {
  const before = doc(sec('sec_1', [blk('a')]));
  const { root } = render(before);
  const fresh = sec('tmp_sec', [blk('tmp_x'), blk('tmp_y')]);
  const r = syncLiveStructure(root, before, doc(sec('sec_1', [blk('a')]), fresh));
  assert.deepEqual(ids(root), ['sec_1', 'a', 'tmp_sec']);
  const ph = root.querySelector('[data-sb-node="tmp_sec"]');
  assert.ok(ph.cls().includes('sbx-pending'));
  assert.deepEqual(r.pending, [ph], 'the section, not its blocks, is what the server renders');
  assert.equal(r.complete, true);
});
