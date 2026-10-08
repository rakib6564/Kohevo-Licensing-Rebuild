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
  const doc2 = { sections: [{ id: 's1', blocks: [{ id: 'b1', type: 'core.heading', props: { text: 'New' }, style: { align: { base: 'center' } } }] }] };
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
  const doc2 = { sections: [{ id: 's1', blocks: [{ id: 'b1', type: 'core.heading', props: { text: 'New Title' }, style: { align: { base: 'center' } } }] }] };

  const count = syncLiveDOM(fakeDoc, doc1, doc2);
  assert.equal(count > 0, true);
  assert.equal(headingEl.textContent, 'New Title');
  assert.equal(wrapperEl.style.textAlign, 'center');
});

test('every other style edit repaints from the server, because its CSS is written there', () => {
  const doc = (block) => ({ sections: [sec([{ id: 'b', type: 'core.heading', props: { text: 'x' }, ...block }])] });
  const base = doc({ style: { typography: { font_size: '2rem' } } });
  for (const [what, next] of [
    ['typography', doc({ style: { typography: { font_size: '3rem' } } })],
    ['a new style group', doc({ style: { typography: { font_size: '2rem' }, effects: { opacity: 0.5 } } })],
    ['a style state', doc({ style: { typography: { font_size: '2rem' } }, style_states: { hover: { effects: { opacity: 0.5 } } } })],
    ['the wrapper tag', doc({ style: { typography: { font_size: '2rem' } }, tag: 'section' })],
    ['class names', doc({ style: { typography: { font_size: '2rem' } }, classNames: ['x'] })],
  ]) {
    assert.equal(isStructuralChange(base, next), true, what);
  }
  assert.equal(isStructuralChange(base, doc({ style: { typography: { font_size: '2rem' } } })), false, 'an equal copy is not a change');
  const withSecStyle = (style) => ({ sections: [{ id: 's1', style, blocks: [] }] });
  assert.equal(isStructuralChange(withSecStyle({ padding: '1rem' }), withSecStyle({ padding: '2rem' })), true, 'section style');
});

test('the live patch no longer writes inline styles the schema does not have', () => {
  const el = fakeEl('div');
  const canvasDoc = { querySelector: () => el };
  const a = { sections: [{ id: 's1', blocks: [{ id: 'b1', type: 'core.heading', props: {}, style: {} }] }] };
  const b = { sections: [{ id: 's1', blocks: [{ id: 'b1', type: 'core.heading', props: {}, style: { typography: { fontSize: '9px' }, textColor: 'red' } }] }] };
  syncLiveDOM(canvasDoc, a, b);
  assert.deepEqual(el.style, {});
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

test('a heading with a highlighted word repaints from the server instead of having its markup flattened', () => {
  const doc = (props) => ({ sections: [sec([blk('h', 'core.heading', props)])] });
  assert.equal(isStructuralChange(doc({ text: 'Get started' }), doc({ text: 'Get started now' })), false, 'plain heading: live patch');
  assert.equal(isStructuralChange(doc({ text: 'Get started', highlight: 'started' }), doc({ text: 'Get started now', highlight: 'started' })), true, 'text edit under a highlight');
  assert.equal(isStructuralChange(doc({ text: 'Get started' }), doc({ text: 'Get started', highlight: 'started' })), true, 'highlight set');
  const el = fakeEl('h2', ['sb-heading'], 'Get <span>started</span>');
  const canvasDoc = { querySelector: () => el };
  syncLiveDOM(canvasDoc, doc({ text: 'Get started', highlight: 'started' }), doc({ text: 'Get going', highlight: 'started' }));
  assert.equal(el.textContent, 'Get <span>started</span>', 'untouched: the server repaint owns it');
});
