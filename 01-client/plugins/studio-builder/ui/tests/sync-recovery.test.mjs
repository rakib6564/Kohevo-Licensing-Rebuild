// SyncEngine: subtrees created by paste or duplicate, and saves whose answer was lost.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { SyncEngine, STATUS, landedAsSent } from '../src/core/sync.mjs';
import * as ops from '../src/core/operations.mjs';
import { findNode } from '../src/core/doc.mjs';
import { manifest, doc, section, heading, container, manualScheduler, fakeServer } from './helpers.mjs';

let minted = 0;
const mint = (p) => `${p}_${String(++minted).padStart(24, 'e')}`;
/** Server-side apply: like the real applier, every node the server creates gets a server id. */
const serverApply = (d, o) => {
  const out = ops.applyLocal(d, o, { manifest, provisionalId: o.op === 'insert_section' || o.op === 'duplicate_section' ? mint('sec') : mint('blk') });
  const rename = (n) => {
    if (typeof n.id === 'string' && n.id.startsWith('tmp_')) n.id = mint('blk');
    for (const k of [...(n.blocks || []), ...(n.children || [])]) rename(k);
  };
  out.sections.forEach(rename);
  return out;
};

function setup(initial = doc([section([heading('Hello')])])) {
  const server = fakeServer(initial, serverApply);
  const sched = manualScheduler();
  const events = [];
  const remaps = [];
  const engine = new SyncEngine({
    transport: server.transport, pageId: 1, debounceMs: 900,
    schedule: sched.schedule, cancel: sched.cancel,
    onRemap: (a, b) => remaps.push([a, b]), onEvent: (e) => events.push(e.type),
  });
  engine.load({ document: JSON.parse(JSON.stringify(server.currentDoc)), page: { id: 1 }, revision: { id: server.currentId } }, manifest);
  return { server, sched, engine, events, remaps };
}

const firstBlock = (d) => d.sections[0].blocks[0];
const settle = async (engine, sched) => { await sched.runAll(); await engine.busy; };

test('a duplicated block keeps its children: every provisional id maps to a server id, edits made before the answer land', async () => {
  const kids = [heading('a'), heading('b')];
  const { engine, server, sched, remaps } = setup(doc([section([container(kids)])]));
  const sourceId = firstBlock(engine.getSnapshot().working).id;
  const tmp = ops.provisionalId('blk');
  assert.equal(engine.apply(ops.duplicateBlock(sourceId), { provisionalId: tmp }), true);
  const copy = findNode(engine.getSnapshot().working, tmp);
  assert.ok(copy, 'the copy carries the entry provisional id');
  const childTmp = copy.node.children[1].id;
  assert.equal(childTmp, `${tmp}_1`, 'descendant ids derive from the root, by position');
  // Edit the copy's child before the server answered; the working copy is rebuilt meanwhile, so the id must hold.
  assert.equal(engine.apply(ops.updateBlockProps(childTmp, { level: 'h2', text: 'edited copy' })), true);
  engine.rebuild();
  assert.ok(findNode(engine.getSnapshot().working, childTmp), 'stable across rebuilds');

  await settle(engine, sched);
  assert.equal(server.calls[0][1].operations.length, 1, 'the duplicate ends its batch');
  const map = new Map(remaps);
  assert.equal(map.size, 3, 'root and both children are mapped');
  const realChild = map.get(childTmp);
  assert.ok(realChild && !realChild.startsWith('tmp_'));
  await settle(engine, sched);
  assert.equal(findNode(server.currentDoc, realChild).node.props.text, 'edited copy');
  assert.equal(findNode(server.currentDoc, kids[1].id).node.props.text, 'b', 'the source is untouched');
  assert.equal(engine.hasUnsavedChanges, false);
});

test('a pasted block with children (an insert carrying a subtree) maps its children too', async () => {
  const { engine, server, sched, remaps } = setup();
  const secId = engine.getSnapshot().working.sections[0].id;
  const tmp = ops.provisionalId('blk');
  const { id: _drop, ...pasted } = container([heading('x'), heading('y')]);
  engine.apply(ops.insertBlock(secId, 1, pasted), { provisionalId: tmp });
  engine.apply(ops.updateBlockProps(`${tmp}_0`, { level: 'h3', text: 'x2' }));
  await settle(engine, sched);
  await settle(engine, sched);
  const real = new Map(remaps).get(`${tmp}_0`);
  assert.ok(real, 'the first child was mapped');
  assert.equal(findNode(server.currentDoc, real).node.props.text, 'x2');
  assert.equal(engine.hasUnsavedChanges, false);
});

test('two duplicates never share a batch', async () => {
  const { engine, server, sched } = setup();
  const id = firstBlock(engine.getSnapshot().working).id;
  engine.apply(ops.duplicateBlock(id), { provisionalId: ops.provisionalId('blk') });
  engine.apply(ops.duplicateBlock(id), { provisionalId: ops.provisionalId('blk') });
  await settle(engine, sched);
  assert.equal(server.calls[0][1].operations.length, 1);
});

test('a save that landed but whose answer was lost is read back, not re-sent into a conflict', async () => {
  const { engine, server, sched, events } = setup();
  const id = firstBlock(engine.getSnapshot().working).id;
  const startRev = server.currentId;
  server.loseAnswer = { ok: false, status: 0, error: { code: 'timeout', message: '' } };
  engine.apply(ops.updateBlockProps(id, { level: 'h2', text: 'landed' }));
  await settle(engine, sched);
  assert.equal(engine.getSnapshot().status, STATUS.ERROR);
  assert.notEqual(server.currentId, startRev, 'the server did take it');

  await settle(engine, sched);
  const s = engine.getSnapshot();
  assert.equal(server.calls.length, 1, 'not sent a second time');
  assert.equal(s.status, STATUS.SAVED);
  assert.equal(s.revision.id, server.currentId);
  assert.equal(engine.hasUnsavedChanges, false);
  assert.deepEqual(s.undo.map((u) => u.before), [startRev], 'one undo step, as for any save');
  assert.ok(!events.includes('conflict'));
});

test('a lost-answer insert is recognised too, and a later edit of the new block goes to its real id', async () => {
  const { engine, server, sched, remaps } = setup();
  const secId = engine.getSnapshot().working.sections[0].id;
  const tmp = ops.provisionalId('blk');
  server.loseAnswer = { ok: false, status: 0, error: { code: 'network_error', message: '' } };
  engine.apply(ops.insertBlock(secId, 1, { type: 'core.heading', props: { level: 'h2', text: 'new' } }), { provisionalId: tmp });
  await settle(engine, sched);
  engine.apply(ops.updateBlockProps(tmp, { level: 'h2', text: 'newer' }));
  await settle(engine, sched);
  await settle(engine, sched);
  assert.equal(server.calls.length, 2, 'the insert once, then the edit');
  const real = new Map(remaps).get(tmp);
  assert.equal(findNode(server.currentDoc, real).node.props.text, 'newer');
  assert.equal(server.currentDoc.sections[0].blocks.length, 2, 'no duplicate block');
});

test('a save that timed out before reaching the server is simply sent again', async () => {
  const { engine, server, sched } = setup();
  const id = firstBlock(engine.getSnapshot().working).id;
  server.failNext = { ok: false, status: 0, error: { code: 'timeout', message: '' } };
  engine.apply(ops.updateBlockProps(id, { level: 'h2', text: 'again' }));
  await settle(engine, sched);
  await settle(engine, sched);
  assert.equal(server.calls.length, 2);
  assert.equal(engine.getSnapshot().status, STATUS.SAVED);
  assert.equal(firstBlock(server.currentDoc).props.text, 'again');
});

test('after a lost answer, another editor\'s write is never taken for ours (a conflict, as before)', async () => {
  const { engine, server, sched } = setup();
  const id = firstBlock(engine.getSnapshot().working).id;
  server.failNext = { ok: false, status: 0, error: { code: 'timeout', message: '' } };
  engine.apply(ops.updateBlockProps(id, { level: 'h2', text: 'mine' }));
  await settle(engine, sched);
  server.externalEdit((d) => { d.sections[0].blocks[0].props.text = 'theirs'; });
  await settle(engine, sched);
  assert.equal(engine.getSnapshot().status, STATUS.CONFLICT);
  assert.equal(firstBlock(server.currentDoc).props.text, 'theirs');
  assert.equal(engine.hasUnsavedChanges, true, 'our edit is kept');
});

test('landedAsSent: exact content match only', () => {
  const base = doc([section([heading('a')])]);
  const id = base.sections[0].blocks[0].id;
  const batch = [{ op: ops.updateBlockProps(id, { level: 'h2', text: 'b' }), provisionalId: null }];
  const same = JSON.parse(JSON.stringify(base));
  same.sections[0].blocks[0].props.text = 'b';
  assert.equal(landedAsSent(base, batch, same, manifest), true);
  const other = JSON.parse(JSON.stringify(same));
  other.sections[0].blocks[0].props.text = 'c';
  assert.equal(landedAsSent(base, batch, other, manifest), false);
});
