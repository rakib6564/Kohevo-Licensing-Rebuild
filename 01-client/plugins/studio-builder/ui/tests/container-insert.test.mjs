// Only a Container sits at the top level: a widget added there is wrapped in a new one in one operation, and a top-level
// Container moves with the same `move_block` as everything else (`parent_id: null`).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as d from '../src/core/doc.mjs';
import * as ops from '../src/core/operations.mjs';
import { lockViolation } from '../src/core/layerLock.mjs';
import { blockInsertState } from '../src/core/addPanel.mjs';
import { SyncEngine } from '../src/core/sync.mjs';
import { manifest, doc, topContainer, heading, container, manualScheduler, fakeServer } from './helpers.mjs';

const def = (type) => d.blockDefinition(manifest, type);
const widgetAt = (where, pid = 'tmp_new') => ({ op: ops.insertOperation(def('core.heading'), where, { type: 'core.heading', props: { level: 'h2', text: 'Hi' } }), pid });

let minted = 0;
const mint = () => `blk_${String(++minted).padStart(24, 'c')}`;
/** Like the real applier: every node the server creates gets a server id. */
const serverApply = (dd, o) => {
  const out = ops.applyLocal(dd, o, { manifest, provisionalId: mint() });
  const rename = (n) => {
    if (typeof n.id === 'string' && n.id.startsWith('tmp_')) n.id = mint();
    (n.children || []).forEach(rename);
  };
  out.containers.forEach(rename);
  return out;
};

function setup(initial) {
  const server = fakeServer(initial, serverApply);
  const sched = manualScheduler();
  const remaps = [];
  const engine = new SyncEngine({ transport: server.transport, pageId: 1, debounceMs: 900, schedule: sched.schedule, cancel: sched.cancel, onRemap: (a, b) => remaps.push([a, b]) });
  engine.load({ document: JSON.parse(JSON.stringify(server.currentDoc)), page: { id: 1 }, revision: { id: server.currentId } }, manifest);
  return { server, sched, engine, remaps };
}

test('a widget on an empty page is wrapped in a new Container, in one insert_block', () => {
  assert.equal(d.insertionPoint(doc([]), manifest, null, 'core.heading'), null, 'there is nowhere for it yet');
  assert.deepEqual(blockInsertState(doc([]), manifest, null, 'core.heading'), { ok: true });
  const { op } = widgetAt({ parentId: null, index: 0 });
  assert.equal(op.op, 'insert_block');
  assert.equal(op.payload.parent_id, null);
  assert.equal(op.payload.block.type, 'core.container');
  assert.deepEqual(op.payload.block.children.map((c) => c.type), ['core.heading']);
  const child = op.payload.block.children[0];
  assert.equal(child.version, def('core.heading').version, 'a subtree is stored as given, so it is whole');
  assert.ok(child.visibility && child.style && child.bindings);

  const next = ops.applyLocal(doc([]), op, { manifest, provisionalId: 'tmp_new' });
  assert.deepEqual(next.containers.map((c) => c.id), ['tmp_new']);
  assert.equal(next.containers[0].children[0].props.text, 'Hi');
  assert.ok(d.findNode(next, ops.insertedId({ parentId: null }, 'core.heading', 'tmp_new')), 'the selection goes to the widget, not its wrapper');
});

test('a widget at the top level of a page with Containers lands in a new one at that index', () => {
  const a = topContainer([heading('A')]); const b = topContainer([heading('B')]);
  const next = ops.applyLocal(doc([a, b]), widgetAt({ parentId: null, index: 1 }).op, { manifest, provisionalId: 'tmp_new' });
  assert.deepEqual(next.containers.map((c) => c.id), [a.id, 'tmp_new', b.id]);
  assert.equal(next.containers[1].children.length, 1);
  assert.equal(next.containers[1].children[0].type, 'core.heading');
});

test('a Container at the top level is not wrapped, and a widget inside a parent is inserted as it is', () => {
  const op = ops.insertOperation(def('core.container'), { parentId: null, index: 2 }, { type: 'core.container', props: { gap: 'lg' } });
  assert.equal(op.payload.block.type, 'core.container');
  assert.deepEqual(op.payload.block.children, []);
  assert.equal(op.payload.block.props.gap, 'lg');
  assert.equal(ops.insertedId({ parentId: null }, 'core.container', 'tmp_x'), 'tmp_x');

  const inside = ops.insertOperation(def('core.heading'), { parentId: 'blk_1', index: 0 }, { type: 'core.heading' });
  assert.deepEqual(inside, ops.insertBlock('blk_1', 0, { type: 'core.heading' }));
  assert.equal(ops.insertedId({ parentId: 'blk_1' }, 'core.heading', 'tmp_x'), 'tmp_x');
});

test('the wrapped widget is one save, one undo step, and its ids are remapped to the server\'s', async () => {
  const { engine, server, sched, remaps } = setup(doc([]));
  const { op } = widgetAt({ parentId: null, index: 0 });
  const pid = ops.provisionalId('blk');
  assert.equal(engine.apply(op, { provisionalId: pid, label: 'Heading' }), true);
  assert.equal(engine.getSnapshot().pending.length, 1, 'one operation');
  await sched.runAll(); await engine.busy;
  assert.equal(server.calls.filter((c) => c[0] === 'operations').length, 1);
  const saved = engine.getSnapshot().working;
  assert.equal(saved.containers.length, 1);
  assert.ok(!saved.containers[0].id.startsWith('tmp_'));
  assert.ok(!saved.containers[0].children[0].id.startsWith('tmp_'), 'the widget has a real id too');
  assert.ok(remaps.some(([from]) => from === `${pid}_0`), 'the selection on the widget can follow it');
  assert.equal(engine.getSnapshot().undo.length, 1);
  await engine.undo();
  await sched.runAll(); await engine.busy;
  assert.equal(engine.getSnapshot().working.containers.length, 0, 'the widget and its Container go together');
});

test('at the Container limit a widget needing its own Container is refused, with the reason', () => {
  const noRoom = { ...manifest, limits: { ...manifest.limits, max_containers: 1 } };
  const onlyGlobal = doc([{ ...topContainer([]), global_ref: 'ref' }]);
  assert.deepEqual(blockInsertState(onlyGlobal, noRoom, null, 'core.heading'), { ok: false, reason: 'containers_limit' });
  assert.equal(d.canInsertBlock(doc([]), manifest, null, 'core.heading'), false, 'the raw insert is never offered at the top level');
  assert.equal(d.canInsertBlock(doc([]), manifest, null, 'core.container'), true);
});

// ── top-level move ───────────────────────────────────────────────────────

const three = () => {
  const a = topContainer([heading('A')], 'A'); const b = topContainer([container([heading('In')])], 'B'); const c = topContainer([], 'C');
  return { a, b, c, x: doc([a, b, c]) };
};

test('a top-level move is a move_block with a null parent, and keeps its subtree', () => {
  const { a, b, c, x } = three();
  const moved = ops.applyLocal(x, ops.moveBlock(c.id, null, 0), { manifest });
  assert.deepEqual(moved.containers.map((n) => n.id), [c.id, a.id, b.id]);
  const down = ops.applyLocal(x, ops.moveBlock(a.id, null, 2), { manifest });
  assert.deepEqual(down.containers.map((n) => n.id), [b.id, c.id, a.id]);
  assert.equal(down.containers[2].children[0].props.text, 'A');
  assert.equal(x.containers[0].id, a.id, 'the input is never mutated');
});

test('keyboard: top-level Containers swap with their neighbours and stop at the ends', () => {
  const { a, b, c, x } = three();
  assert.deepEqual(d.blockMoveTarget(x, manifest, b.id, 'up'), { parentId: null, index: 0 });
  assert.deepEqual(d.blockMoveTarget(x, manifest, b.id, 'down'), { parentId: null, index: 2 });
  assert.equal(d.blockMoveTarget(x, manifest, a.id, 'up'), null);
  assert.equal(d.blockMoveTarget(x, manifest, c.id, 'down'), null);
});

test('what may move to the top level: a Container, never a widget', () => {
  const { a, b, x } = three();
  const nested = b.children[0];
  assert.equal(d.canMoveBlock(x, manifest, nested.id, null), true);
  assert.equal(d.canMoveBlock(x, manifest, a.children[0].id, null), false, 'a widget stays in a Container');
  assert.deepEqual(d.blockOutdentTarget(x, manifest, nested.id), { parentId: null, index: 2 });
  assert.equal(d.blockOutdentTarget(x, manifest, a.children[0].id), null);
  assert.equal(d.blockOutdentTarget(x, manifest, a.id), null);
  const moved = ops.applyLocal(x, ops.moveBlock(nested.id, null, 1), { manifest });
  assert.equal(d.findNode(moved, nested.id).parentId, null);
  assert.equal(moved.containers.length, 4);
});

test('a top-level move respects the Container limit, depth, references and locks', () => {
  const { a, b, c, x } = three();
  const nested = b.children[0];
  const full = { ...manifest, limits: { ...manifest.limits, max_containers: 3 } };
  assert.equal(d.canMoveBlock(x, full, nested.id, null), false, 'a nested one would add a fourth');
  assert.equal(d.canMoveBlock(x, full, a.id, null), true, 'a reorder adds none');

  assert.equal(d.canMoveBlock(x, manifest, c.id, a.id), true, 'a top-level Container can nest in another');
  const g = { ...topContainer([]), global_ref: 'ref' };
  const y = doc([a, g]);
  assert.equal(d.canMoveBlock(y, manifest, g.id, a.id), false, 'a reference stays at the top level');
  assert.equal(d.canMoveBlock(y, manifest, g.id, null), true);

  const locked = ops.applyLocal(x, ops.updateBlockMeta(a.id, { locked: true }));
  assert.equal(lockViolation(locked, ops.moveBlock(a.id, null, 2)), 'locked');
  assert.equal(lockViolation(locked, ops.moveBlock(c.id, null, 0)), null, 'moving another Container past it is not inside the lock');
});

test('a top-level move saves and survives a reload', async () => {
  const { a, b, c, x } = three();
  const { engine, server, sched } = setup(x);
  engine.apply(ops.moveBlock(c.id, null, 0));
  await sched.runAll(); await engine.busy;
  assert.deepEqual(server.currentDoc.containers.map((n) => n.id), [c.id, a.id, b.id]);
  assert.deepEqual(engine.getSnapshot().working.containers.map((n) => n.id), [c.id, a.id, b.id]);
  await engine.undo();
  await sched.runAll(); await engine.busy;
  assert.deepEqual(engine.getSnapshot().working.containers.map((n) => n.id), [a.id, b.id, c.id]);
});
