import { test } from 'node:test';
import assert from 'node:assert/strict';
import { isStructuralChange, syncLiveDOM } from '../src/core/canvasLiveSync.mjs';

function fakeEl(tag = 'div', classes = [], text = '') {
  const classList = new Set(classes);
  const styleMap = {};
  const attrs = {};
  const kids = [];
  return {
    tagName: tag.toUpperCase(),
    textContent: text,
    innerHTML: text,
    children: kids,
    classList: {
      add: (...cls) => cls.forEach((c) => classList.add(c)),
      remove: (...cls) => cls.forEach((c) => classList.delete(c)),
      contains: (c) => classList.has(c),
    },
    style: styleMap,
    setAttribute: (k, v) => { attrs[k] = String(v); },
    getAttribute: (k) => attrs[k] || null,
    querySelector: (sel) => {
      if (sel.includes('heading') || sel.includes('h2') || sel.includes('h1')) {
        return kids.find((k) => k.tagName === 'H2' || k.tagName === 'H1') || null;
      }
      if (sel.includes('text') || sel.includes('p')) {
        return kids.find((k) => k.tagName === 'P') || null;
      }
      return null;
    },
    querySelectorAll: (sel) => kids,
    _classes: classList,
    _attrs: attrs,
  };
}

test('isStructuralChange detects section additions and deletions', () => {
  const doc1 = { sections: [{ id: 's1', blocks: [] }] };
  const doc2 = { sections: [{ id: 's1', blocks: [] }, { id: 's2', blocks: [] }] };
  assert.equal(isStructuralChange(doc1, doc2), true);
  assert.equal(isStructuralChange(doc1, doc1), false);
});

test('isStructuralChange returns false for prop and style edits', () => {
  const doc1 = { sections: [{ id: 's1', blocks: [{ id: 'b1', type: 'core.heading', props: { text: 'Old' }, style: {} }] }] };
  const doc2 = { sections: [{ id: 's1', blocks: [{ id: 'b1', type: 'core.heading', props: { text: 'New' }, style: { textColor: '#ff0000' } }] }] };
  assert.equal(isStructuralChange(doc1, doc2), false);
});

test('syncLiveDOM updates block text and style immediately', () => {
  const headingEl = fakeEl('h2', ['sb-heading'], 'Old Title');
  const wrapperEl = fakeEl('div', ['sb-block']);
  wrapperEl.children.push(headingEl);

  const fakeDoc = {
    querySelector: (sel) => {
      if (sel.includes('b1')) return wrapperEl;
      return null;
    },
  };

  const doc1 = { sections: [{ id: 's1', blocks: [{ id: 'b1', type: 'core.heading', props: { text: 'Old Title' } }] }] };
  const doc2 = { sections: [{ id: 's1', blocks: [{ id: 'b1', type: 'core.heading', props: { text: 'New Title' }, style: { textColor: '#10b981' } }] }] };

  const count = syncLiveDOM(fakeDoc, doc1, doc2);
  assert.equal(count > 0, true);
  assert.equal(headingEl.textContent, 'New Title');
  assert.equal(wrapperEl.style.color, '#10b981');
});

const sec = (blocks) => ({ id: 's1', blocks });
const blk = (id, type, props = {}, children = []) => ({ id, type, props, children });

test('isStructuralChange sees a block added or removed at any depth (nesting goes to 6)', () => {
  const deep = (extra) => ({ sections: [sec([blk('a', 'core.container', {}, [blk('b', 'core.container', {}, [blk('c', 'core.container', {}, [blk('d', 'core.container', {}, extra)])])])])] });
  assert.equal(isStructuralChange(deep([]), deep([blk('e', 'core.heading')])), true, 'a child added four levels down');
  assert.equal(isStructuralChange(deep([blk('e', 'core.heading')]), deep([blk('e', 'core.text')])), true, 'a type changed four levels down');
  assert.equal(isStructuralChange(deep([blk('e', 'core.heading')]), deep([blk('e', 'core.heading', { text: 'x' })])), false, 'a prop edit on a patched type is still live');
});

test('server-rendered elements repaint from the server on a prop edit, and are never heuristically patched', () => {
  for (const type of ['core.icon', 'core.list', 'core.quote', 'core.link']) {
    const a = { sections: [sec([blk('x', type, { name: 'star', text: 'One' })])] };
    const b = { sections: [sec([blk('x', type, { name: 'heart', text: 'Two' })])] };
    assert.equal(isStructuralChange(a, b), true, `${type}: a prop edit repaints`);
    assert.equal(isStructuralChange(a, { sections: [sec([blk('x', type, { name: 'star', text: 'One' })])] }), false, `${type}: identical props do not`);
  }
  const el = fakeEl('figure', [], 'untouched');
  const canvasDoc = { querySelector: () => el };
  const a = { sections: [sec([blk('x', 'core.quote', { text: 'One' })])] };
  const b = { sections: [sec([blk('x', 'core.quote', { text: 'Two' })])] };
  assert.equal(syncLiveDOM(canvasDoc, a, b), 0, 'nothing patched');
  assert.equal(el.textContent, 'untouched');
});
