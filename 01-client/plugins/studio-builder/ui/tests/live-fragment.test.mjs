// A block the author has just inserted gets its real markup before the save returns: the server's render becomes
// one element with the document's ids, plus only the CSS rules that element needs.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { adoptFromPage, applyFragment, clearFragments, rulesFor } from '../src/core/liveFragment.mjs';

class El {
  constructor(tag, attrs = {}) { this.tagName = tag; this.attrs = new Map(Object.entries(attrs)); this.kids = []; this.parentNode = null; this.text = ''; this.isStyle = tag === 'style'; }
  get classList() { const self = this; return { [Symbol.iterator]() { return (self.attrs.get('class') || '').split(/\s+/).filter(Boolean).values(); } }; }
  add(k) { k.parentNode = this; this.kids.push(k); return k; }
  appendChild(k) { return this.add(k); }
  get id() { return this.getAttribute('id') || ''; }
  set id(v) { this.setAttribute('id', v); }
  setAttribute(n, v) { this.attrs.set(n, String(v)); }
  getAttribute(n) { return this.attrs.has(n) ? this.attrs.get(n) : null; }
  all() { return this.kids.flatMap((c) => [c, ...c.all()]); }
  querySelectorAll(sel) {
    if (sel === 'style') return this.all().filter((e) => e.isStyle);
    if (sel === '[class]') return this.all().filter((e) => e.attrs.has('class'));
    const a = /^\[([\w-]+)\]$/.exec(sel)[1];
    return this.all().filter((e) => e.attrs.has(a));
  }
  querySelector(sel) { if (sel === 'main') return this.all().find((e) => e.tagName === 'main') || null; return this.querySelectorAll(sel)[0] || null; }
  get textContent() { return this.text; }
  set textContent(v) { this.text = v; }
  remove() { if (this.parentNode) this.parentNode.kids.splice(this.parentNode.kids.indexOf(this), 1); this.parentNode = null; }
  replaceChild(n, old) { const i = this.kids.indexOf(old); this.kids[i] = n; n.parentNode = this; old.parentNode = null; }
}
const canvasDoc = () => {
  const head = new El('head');
  return { head, importNode: (e) => e, createElement: (t) => new El(t), querySelectorAll: (sel) => head.all().filter((e) => sel.startsWith('style[id^=') && e.isStyle && (e.attrs.get('id') || '').startsWith('sbx-frag-')) };
};
const serverPage = (blockAttrs, kids = [], css = '.sb-x-aaaaaaaaaaaaaaaa{padding:1rem}.sb-x-bbbbbbbbbbbbbbbb{margin:0}') => {
  const page = new El('html'); const main = page.add(new El('main'));
  const section = main.add(new El('section', { 'data-sb-node': 'sec_server' }));
  const block = section.add(new El('div', blockAttrs));
  for (const k of kids) block.add(new El('div', k));
  const style = page.add(new El('style')); style.text = css;
  return page;
};
const node = { id: 'tmp_new', children: [{ id: 'tmp_kid', children: [] }] };
class Sheet { replaceSync(t) { this.cssRules = t.split('}').filter(Boolean).map((r) => ({ cssText: `${r}}` })); } }

test('the block comes out with the document ids, in document order', () => {
  const page = serverPage({ 'data-sb-node': 'blk_server1', class: 'sb-block sb-x-aaaaaaaaaaaaaaaa' }, [{ 'data-sb-node': 'blk_server2' }]);
  const out = adoptFromPage(page, '<div class="sb-block">', node, canvasDoc());
  assert.equal(out.element.getAttribute('data-sb-node'), 'tmp_new');
  assert.equal(out.element.kids[0].getAttribute('data-sb-node'), 'tmp_kid');
});

test('a render with a different number of nodes than the document is not used', () => {
  const page = serverPage({ 'data-sb-node': 'blk_server1' }, []);
  assert.equal(adoptFromPage(page, '', node, canvasDoc()), null);
});

test('an unavailable notice, or no block at all, leaves the placeholder alone', () => {
  const page = serverPage({ 'data-sb-node': 'blk_server1' }, [{ 'data-sb-node': 'blk_server2' }]);
  assert.equal(adoptFromPage(page, '<div class="sb-unavailable" role="note">', node, canvasDoc()), null);
  const empty = new El('html'); empty.add(new El('main'));
  assert.equal(adoptFromPage(empty, '', node, canvasDoc()), null);
  assert.equal(adoptFromPage(null, '', node, canvasDoc()), null);
});

test('only the rules for the classes the block uses are kept', () => {
  assert.equal(rulesFor('.sb-x-aaaaaaaaaaaaaaaa{padding:1rem}.sb-x-bbbbbbbbbbbbbbbb{margin:0}.sb-heading{font-size:2rem}', ['sb-x-aaaaaaaaaaaaaaaa'], Sheet), '.sb-x-aaaaaaaaaaaaaaaa{padding:1rem}');
  assert.equal(rulesFor('', ['x'], Sheet), '');
  assert.equal(rulesFor('.a{}', [], Sheet), '');
  assert.equal(rulesFor('.a{}', ['a'], undefined), '', 'no CSS parser: nothing is guessed');
});

test('the CSS of the fragment rides in an editor-owned sheet that the server render clears', () => {
  const doc = canvasDoc();
  const parent = new El('div'); const ph = parent.add(new El('div')); const real = new El('div');
  assert.equal(applyFragment(doc, ph, { element: real, css: '.sb-x-aaaaaaaaaaaaaaaa{padding:1rem}' }, 'tmp_new'), true);
  assert.deepEqual(parent.kids, [real]);
  const sheet = doc.head.kids[0];
  assert.equal(sheet.getAttribute('id'), 'sbx-frag-tmp_new');
  assert.equal(sheet.getAttribute('data-sbx-owned'), '1');
  clearFragments(doc);
  assert.equal(doc.head.kids.length, 0);
  assert.equal(applyFragment(doc, new El('div'), { element: real, css: '' }, 'x'), false, 'a placeholder that is gone is not swapped');
});
