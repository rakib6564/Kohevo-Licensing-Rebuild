// Layer lock and block rename: the client mirror of LayerLock.php, the local
// (optimistic) apply of the two new operations, display names, and the engine
// refusing a locked edit up front.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { manifest, doc, section, heading, container, fakeServer, manualScheduler } from './helpers.mjs';
import * as ops from '../src/core/operations.mjs';
import { applyLocal } from '../src/core/operations.mjs';
import { findNode, nodeLabel } from '../src/core/doc.mjs';
import { SyncEngine } from '../src/core/sync.mjs';
import { isLocked, lockIndex, effectivelyLocked, ancestorLocked, hasLockedDescendant, lockViolation } from '../src/core/layerLock.mjs';

/** section → [container → [inner heading], sibling heading] */
function fixture() {
  const inner = heading('Inner');
  const box = container([inner]);
  const sibling = heading('Sibling');
  const sec = section([box, sibling]);
  return { d: doc([sec]), sec, box, inner, sibling };
}
const lock = (d, id, locked = true) => {
  const op = findNode(d, id).kind === 'section' ? ops.updateSectionLocked(id, locked) : ops.updateBlockMeta(id, { locked });
  return applyLocal(d, op);
};

test('update_block_meta sets, trims and clears the label; empty metadata disappears', () => {
  const { d, sibling } = fixture();
  let next = applyLocal(d, ops.updateBlockMeta(sibling.id, { label: '  Promo  ' }));
  assert.deepEqual(findNode(next, sibling.id).node.metadata, { label: 'Promo' });
  next = applyLocal(next, ops.updateBlockMeta(sibling.id, { locked: true }));
  assert.deepEqual(findNode(next, sibling.id).node.metadata, { label: 'Promo', locked: true });
  next = applyLocal(next, ops.updateBlockMeta(sibling.id, { label: null, locked: false }));
  assert.equal('metadata' in findNode(next, sibling.id).node, false);
  // The source document is never mutated.
  assert.equal('metadata' in findNode(d, sibling.id).node, false);
});

test('update_section_locked adds the key only while locked', () => {
  const { d, sec } = fixture();
  const locked = applyLocal(d, ops.updateSectionLocked(sec.id, true));
  assert.equal(locked.sections[0].locked, true);
  assert.equal('locked' in applyLocal(locked, ops.updateSectionLocked(sec.id, false)).sections[0], false);
  assert.throws(() => applyLocal(d, ops.updateSectionLocked('sec_missing', true)));
  assert.throws(() => applyLocal(d, ops.updateBlockMeta('blk_missing', { label: 'x' })));
});

test('a block display name wins over the generated label; clearing restores it', () => {
  const { d, sibling } = fixture();
  assert.equal(nodeLabel(findNode(d, sibling.id).node, manifest, 'block'), 'Heading: Sibling');
  const named = applyLocal(d, ops.updateBlockMeta(sibling.id, { label: 'Promo banner' }));
  assert.equal(nodeLabel(findNode(named, sibling.id).node, manifest, 'block'), 'Promo banner');
  const cleared = applyLocal(named, ops.updateBlockMeta(sibling.id, { label: '' }));
  assert.equal(nodeLabel(findNode(cleared, sibling.id).node, manifest, 'block'), 'Heading: Sibling');
});

test('lock index: own lock, inherited lock, ancestor lock and locked descendants', () => {
  const { d, sec, box, inner, sibling } = fixture();
  const idx = lockIndex(lock(d, box.id));
  assert.equal(isLocked(findNode(lock(d, box.id), box.id).node), true);
  assert.equal(effectivelyLocked(idx, box.id), true);
  assert.equal(effectivelyLocked(idx, inner.id), true);
  assert.equal(ancestorLocked(idx, inner.id), true);
  assert.equal(ancestorLocked(idx, box.id), false);
  assert.equal(effectivelyLocked(idx, sibling.id), false);
  assert.equal(hasLockedDescendant(idx, sec.id), true);
  assert.equal(hasLockedDescendant(idx, inner.id), false);
});

test('lockViolation: a locked block refuses edits, moves, rename and removal; others stay editable', () => {
  const { d, sec, sibling, box } = fixture();
  const locked = lock(d, sibling.id);
  const edit = ops.updateBlockProps(sibling.id, { text: 'x', level: 'h2' });
  assert.equal(lockViolation(locked, edit), 'locked');
  assert.equal(lockViolation(locked, ops.updateBlockStyle(sibling.id, {})), 'locked');
  assert.equal(lockViolation(locked, ops.updateBlockMeta(sibling.id, { label: 'Renamed' })), 'locked');
  assert.equal(lockViolation(locked, ops.moveBlock(sibling.id, sec.id, 0)), 'locked');
  assert.equal(lockViolation(locked, ops.removeBlock(sibling.id)), 'locked');
  assert.equal(lockViolation(locked, ops.updateBlockMeta(box.id, { label: 'Fine' })), null);
  assert.equal(lockViolation(locked, ops.duplicateBlock(sibling.id)), null, 'duplicating stays allowed');
});

test('lockViolation: locked containers and sections protect everything inside', () => {
  const { d, sec, box, inner, sibling } = fixture();
  const lockedBox = lock(d, box.id);
  assert.equal(lockViolation(lockedBox, ops.updateBlockProps(inner.id, {})), 'locked');
  assert.equal(lockViolation(lockedBox, ops.insertBlock(box.id, 0, { type: 'core.heading' })), 'locked');
  assert.equal(lockViolation(lockedBox, ops.moveBlock(sibling.id, box.id, 0)), 'locked');
  assert.equal(lockViolation(lockedBox, ops.moveBlock(inner.id, sec.id, 0)), 'locked');

  const lockedSec = lock(d, sec.id);
  assert.equal(lockViolation(lockedSec, ops.updateSectionLabel(sec.id, 'x')), 'locked');
  assert.equal(lockViolation(lockedSec, ops.moveSection(sec.id, 0)), 'locked');
  assert.equal(lockViolation(lockedSec, ops.removeSection(sec.id)), 'locked');
  assert.equal(lockViolation(lockedSec, ops.insertBlock(sec.id, 0, { type: 'core.heading' })), 'locked');
  assert.equal(lockViolation(lockedSec, ops.updateBlockProps(sibling.id, {})), 'locked');
  assert.equal(lockViolation(lockedSec, ops.insertSection(1, {})), null, 'a new top-level section is not inside the lock');
});

test('lockViolation: a parent holding a locked child cannot be removed, so locks cannot be deleted away', () => {
  const { d, sec, box, inner } = fixture();
  const locked = lock(d, inner.id);
  assert.equal(lockViolation(locked, ops.removeBlock(box.id)), 'locked');
  assert.equal(lockViolation(locked, ops.removeSection(sec.id)), 'locked');
  // Moving the parent keeps the locked child intact, so it is allowed.
  assert.equal(lockViolation(locked, ops.moveBlock(box.id, sec.id, 1)), null);
});

test('lockViolation: unlocking is allowed from the outermost lock only; unknown ids are never locked', () => {
  const { d, sec, inner } = fixture();
  const both = lock(lock(d, inner.id), sec.id);
  assert.equal(lockViolation(both, ops.updateBlockMeta(inner.id, { locked: false })), 'locked');
  assert.equal(lockViolation(both, ops.updateSectionLocked(sec.id, false)), null);
  const afterSection = applyLocal(both, ops.updateSectionLocked(sec.id, false));
  assert.equal(lockViolation(afterSection, ops.updateBlockMeta(inner.id, { locked: false })), null);
  assert.equal(lockViolation(both, ops.updateBlockProps('tmp_blk_pending', {})), null);
});

test('engine: a locked edit is refused up front with a locked event and nothing is queued', async () => {
  const { d, sibling } = fixture();
  const locked = lock(d, sibling.id);
  const server = fakeServer(locked, (cur) => cur);
  const sched = manualScheduler();
  const events = [];
  const engine = new SyncEngine({ transport: server.transport, pageId: 1, schedule: sched.schedule, cancel: sched.cancel, onEvent: (e) => events.push(e.type) });
  engine.load({ document: JSON.parse(JSON.stringify(locked)), page: { id: 1 }, revision: { id: server.currentId } }, manifest);

  assert.equal(engine.apply(ops.updateBlockProps(sibling.id, { text: 'nope', level: 'h2' })), false);
  assert.ok(events.includes('locked'));
  assert.equal(engine.getSnapshot().pending.length, 0);
  assert.equal(findNode(engine.getSnapshot().working, sibling.id).node.props.text, 'Sibling');

  // Unlocking is accepted and queued.
  assert.equal(engine.apply(ops.updateBlockMeta(sibling.id, { locked: false })), true);
  assert.equal(engine.getSnapshot().pending.length, 1);
});
