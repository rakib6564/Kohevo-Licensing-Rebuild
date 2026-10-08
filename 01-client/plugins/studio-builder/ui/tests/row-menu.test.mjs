import { test } from 'node:test';
import assert from 'node:assert/strict';
import { block, doc, manifest, section } from './helpers.mjs';
import { nextEnabled, rowMenuItems } from '../src/core/rowMenu.mjs';
import { lockIndex } from '../src/core/layerLock.mjs';
import { partialRef } from '../src/core/pages.mjs';

/** The row the Layers tree builds for a node (the fields the menu reads). */
function row(d, id) {
  for (const [sIndex, s] of d.sections.entries()) {
    if (s.id === id) return { id, kind: 'section', index: sIndex, setSize: d.sections.length, parentId: null, node: s };
    const walk = (blocks, parentId) => {
      for (const [i, b] of blocks.entries()) {
        if (b.id === id) return { id, kind: 'block', index: i, setSize: blocks.length, parentId, node: b };
        const hit = walk(b.children || [], b.id);
        if (hit) return hit;
      }
      return null;
    };
    const hit = walk(s.blocks, s.id);
    if (hit) return hit;
  }
  return null;
}
const keys = (items) => items.map((i) => i.key);
const byKey = (items, k) => items.find((i) => i.key === k);
const menu = (d, id, extra = {}) => rowMenuItems({ row: row(d, id), doc: d, manifest, locks: lockIndex(d), ...extra });

test('every row offers rename, duplicate, lock, hide, move up/down and delete, in that order', () => {
  const a = block('core.heading', { text: 'A', level: 'h2' });
  const d = doc([section([a])]);
  assert.deepEqual(keys(menu(d, a.id)), ['rename', 'duplicate', 'lock', 'hide', 'move_up', 'move_down', 'delete']);
});

test('lock and hide are toggles: the entry names the action it will perform', () => {
  const a = block('core.heading', { text: 'A', level: 'h2' });
  a.visibility = { ...a.visibility, devices: [] };
  a.metadata = { locked: true };
  const d = doc([section([a])]);
  const k = keys(menu(d, a.id));
  assert.ok(k.includes('show') && !k.includes('hide'));
  assert.ok(k.includes('unlock') && !k.includes('lock'));
});

test('move entries follow the structure: first cannot go up, last cannot go down, a lone block can cross sections', () => {
  const a = block('core.heading', { text: 'A', level: 'h2' });
  const b = block('core.heading', { text: 'B', level: 'h2' });
  const d = doc([section([a, b])]);
  assert.equal(byKey(menu(d, a.id), 'move_up').disabled, true, 'nothing above the first block of the only section');
  assert.equal(byKey(menu(d, a.id), 'move_down').disabled, false);
  assert.equal(byKey(menu(d, b.id), 'move_down').disabled, true);
  const two = doc([section([a]), section([b])]);
  assert.equal(byKey(menu(two, a.id), 'move_down').disabled, false, 'into the next section');
  assert.equal(byKey(menu(two, two.sections[0].id), 'move_up').disabled, true, 'the first section');
  assert.equal(byKey(menu(two, two.sections[1].id), 'move_down').disabled, true, 'the last section');
  assert.equal(byKey(menu(two, two.sections[0].id), 'move_down').disabled, false);
});

test('a locked layer, or one under a locked parent, disables everything that changes it, and says why', () => {
  const inner = block('core.heading', { text: 'in', level: 'h2' });
  const parent = block('core.container', {}, [inner]);
  parent.metadata = { locked: true };
  const d = doc([section([parent])]);
  const own = menu(d, parent.id);
  for (const k of ['rename', 'move_up', 'move_down', 'delete']) assert.equal(byKey(own, k).disabled, true, `${k} on a locked layer`);
  assert.equal(byKey(own, 'duplicate').disabled, undefined, 'duplicating stays possible, as on the canvas toolbar');
  assert.ok(byKey(own, 'unlock'), 'its own lock can be lifted');
  const child = menu(d, inner.id);
  assert.equal(byKey(child, 'lock').disabled, true, 'cannot lock under a locked parent');
  assert.equal(byKey(child, 'delete').reasonKey, 'locked_by_parent');
});

test('"Save to library" needs the admin permission and a node that can be a template', () => {
  const a = block('core.heading', { text: 'A', level: 'h2' });
  const d = doc([section([a])]);
  assert.ok(!keys(menu(d, a.id)).includes('save_library'));
  assert.ok(keys(menu(d, a.id, { canSaveToLibrary: true })).includes('save_library'));
  assert.ok(keys(menu(d, d.sections[0].id, { canSaveToLibrary: true })).includes('save_library'), 'a section can be saved');
  const global = doc([{ ...section([]), global_ref: '11111111-2222-4333-8444-555555555555' }]);
  assert.ok(!keys(menu(global, global.sections[0].id, { canSaveToLibrary: true })).includes('save_library'), 'a global-component reference is not copied into a template');
});

test('delete is the last, dangerous entry', () => {
  const a = block('core.heading', { text: 'A', level: 'h2' });
  const items = menu(doc([section([a])]), a.id);
  assert.equal(items[items.length - 1].key, 'delete');
  assert.equal(items[items.length - 1].danger, true);
});

test('arrowing skips disabled entries and wraps', () => {
  const items = [{ disabled: false }, { disabled: true }, { disabled: false }, { disabled: true }];
  assert.equal(nextEnabled(items, 0, 1), 2);
  assert.equal(nextEnabled(items, 2, 1), 0, 'wraps past the disabled tail');
  assert.equal(nextEnabled(items, 0, -1), 2);
  assert.equal(nextEnabled(items, -1, 1), 0, 'from before the start');
  assert.equal(nextEnabled([{ disabled: true }], 0, 1), null);
});

test('a header or footer region resolves to a reference: hidden, own, shared or built-in', () => {
  const page = (id, title) => ({ id, title });
  assert.equal(partialRef(null), null);
  assert.deepEqual(partialRef({ resolved: 'hidden' }), { kind: 'hidden', page: null });
  assert.deepEqual(partialRef({ resolved: 'builtin', site: null, custom: null }), { kind: 'builtin', page: null });
  assert.deepEqual(partialRef({ resolved: 'site', site: page(7, 'Site header') }), { kind: 'site', page: { id: 7, title: 'Site header' } });
  assert.deepEqual(partialRef({ resolved: 'custom', custom: page(9, 'About header'), site: page(7, 'Site header') }), { kind: 'custom', page: { id: 9, title: 'About header' } });
  assert.equal(partialRef({ resolved: 'site', site: null }).kind, 'builtin', 'a stale reference falls back to the built-in markup');
});
