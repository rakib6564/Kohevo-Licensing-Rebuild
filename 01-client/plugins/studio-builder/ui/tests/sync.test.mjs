// SyncEngine: autosave, reconciliation, provisional ids, conflicts, undo/redo.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { SyncEngine, STATUS } from '../src/core/sync.mjs';
import * as ops from '../src/core/operations.mjs';
import { findNode, nodeIds } from '../src/core/doc.mjs';
import { manifest, doc, section, heading, container, manualScheduler, fakeServer } from './helpers.mjs';

let minted = 0;
/** Server-side apply: same applier semantics, but ids are minted by "the server". */
const serverApply = (d, o) => {
  const mint = o.op === 'insert_block' ? `blk_${String(++minted).padStart(24, 'f')}` : o.op === 'insert_section' ? `sec_${String(++minted).padStart(24, 'f')}` : undefined;
  return ops.applyLocal(d, o, { manifest, provisionalId: mint });
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
  const snap = { document: JSON.parse(JSON.stringify(server.currentDoc)), page: { id: 1 }, revision: { id: server.currentId } };
  engine.load(snap, manifest);
  return { server, sched, engine, events, remaps };
}

const firstBlock = (d) => d.sections[0].blocks[0];

test('load: the editor state IS the server document; nothing pending', () => {
  const { engine, server } = setup();
  const s = engine.getSnapshot();
  assert.equal(s.status, STATUS.IDLE);
  assert.deepEqual(s.working, server.currentDoc);
  assert.equal(s.revision.id, server.currentId);
  assert.equal(engine.hasUnsavedChanges, false);
});

test('property edits: optimistic, coalesced, debounced autosave with expected_revision_id, then reconciled', async () => {
  const { engine, server, sched } = setup();
  const id = firstBlock(engine.getSnapshot().working).id;
  const startRev = server.currentId;
  engine.apply(ops.updateBlockProps(id, { level: 'h2', text: 'He' }));
  engine.apply(ops.updateBlockProps(id, { level: 'h2', text: 'Hey' }));
  engine.apply(ops.updateBlockProps(id, { level: 'h2', text: 'Hey there' }));
  assert.equal(engine.getSnapshot().status, STATUS.DIRTY);
  assert.equal(firstBlock(engine.getSnapshot().working).props.text, 'Hey there', 'optimistic local update');
  assert.equal(engine.getSnapshot().pending.length, 1, 'keystrokes coalesce into one operation');
  assert.equal(server.calls.length, 0, 'nothing sent before the debounce fires');
  assert.equal(sched.pending()[0].ms, 900);

  await sched.runAll();
  await engine.busy;
  assert.equal(server.calls.length, 1);
  const [action, body] = server.calls[0];
  assert.equal(action, 'operations');
  assert.equal(body.revision_kind, 'autosave');
  assert.equal(body.expected_revision_id, startRev);
  assert.deepEqual(body.operations, [ops.updateBlockProps(id, { level: 'h2', text: 'Hey there' })]);
  const s = engine.getSnapshot();
  assert.equal(s.status, STATUS.SAVED);
  assert.equal(s.revision.id, server.currentId, 'the new server revision becomes the base');
  assert.deepEqual(s.base, server.currentDoc, 'base replaced by the server document (reconciliation)');
  assert.equal(s.undo.length, 1);
});

test('structural edits flush immediately; provisional id is replaced by the server-minted id', async () => {
  const { engine, server, sched, remaps } = setup();
  const secId = engine.getSnapshot().working.sections[0].id;
  const tmp = ops.provisionalId('blk');
  engine.apply(ops.insertBlock(secId, 1, { type: 'core.heading' }), { provisionalId: tmp });
  assert.ok(findNode(engine.getSnapshot().working, tmp), 'visible optimistically under its provisional id');
  assert.equal(sched.pending()[0].ms, 0, 'structural ops are not debounced');
  // Edit the new block BEFORE the server confirmed it.
  engine.apply(ops.updateBlockProps(tmp, { level: 'h3', text: 'Brand new' }));

  await sched.runAll(); await engine.busy;
  assert.equal(server.calls.length, 1, 'the insert is sent alone (batch ends at the insert)');
  assert.equal(server.calls[0][1].operations.length, 1);
  assert.equal(remaps.length, 1);
  const [from, to] = remaps[0];
  assert.equal(from, tmp);
  assert.match(to, /^blk_/);
  const pendingOp = engine.getSnapshot().pending[0].op;
  assert.equal(pendingOp.payload.block_id, to, 'queued edit now targets the real id');

  await sched.runAll(); await engine.busy;
  assert.equal(server.calls.length, 2);
  assert.equal(server.calls[1][1].operations[0].payload.block_id, to, 'no provisional id ever reaches the server');
  assert.equal(findNode(server.currentDoc, to).node.props.text, 'Brand new');
  for (const [, body] of server.calls) {
    assert.ok(!JSON.stringify(body).includes('tmp_'), 'provisional ids never leave the browser');
  }
});

test('concurrency: a stale revision enters the conflict state and never overwrites the newer server revision', async () => {
  const { engine, server, sched, events } = setup();
  const id = firstBlock(engine.getSnapshot().working).id;
  server.externalEdit((d) => { d.sections[0].blocks[0].props.text = 'Written by user A'; });
  const newerRev = server.currentId;

  engine.apply(ops.updateBlockProps(id, { level: 'h2', text: 'Written by user B' }));
  await sched.runAll(); await engine.busy;

  const s = engine.getSnapshot();
  assert.equal(s.status, STATUS.CONFLICT);
  assert.equal(s.conflict.currentRevisionId, newerRev, 'the server revision that changed is reported');
  assert.equal(s.conflict.localChangeCount, 1, 'local changes still exist');
  assert.equal(engine.hasUnsavedChanges, true);
  assert.ok(events.includes('conflict'));
  assert.equal(server.currentDoc.sections[0].blocks[0].props.text, 'Written by user A', 'server revision untouched');
  assert.equal(server.currentId, newerRev, 'no revision created by the rejected save');

  // No further writes while in conflict: edits are refused, nothing is scheduled or sent.
  assert.equal(engine.apply(ops.updateBlockProps(id, { level: 'h2', text: 'again' })), false);
  assert.equal(sched.pending().length, 0);
  assert.equal(await engine.save(), false);
  assert.equal(server.calls.length, 1);

  // Explicit reload reconstructs from the server; local edits are discarded, not merged.
  assert.equal(await engine.reloadFromServer(), true);
  const r = engine.getSnapshot();
  assert.equal(r.status, STATUS.IDLE);
  assert.equal(r.revision.id, newerRev);
  assert.equal(firstBlock(r.working).props.text, 'Written by user A');
  assert.equal(engine.hasUnsavedChanges, false);
});

test('validation rejection drops only the rejected edit and reverts to the server state', async () => {
  const { engine, server, sched, events } = setup();
  const id = firstBlock(engine.getSnapshot().working).id;
  server.failNext = { ok: false, status: 422, error: { code: 'validation_error', message: '', details: { errors: [] } } };
  engine.apply(ops.updateBlockProps(id, { level: 'h2', text: 'bad' }));
  await sched.runAll(); await engine.busy;
  const s = engine.getSnapshot();
  assert.equal(s.status, STATUS.ERROR);
  assert.equal(s.pending.length, 0);
  assert.equal(firstBlock(s.working).props.text, 'Hello', 'working copy reverted to the server document');
  assert.ok(events.includes('rejected'));
});

test('transient failure keeps the edits and schedules a retry (no browser persistence involved)', async () => {
  const { engine, server, sched } = setup();
  const id = firstBlock(engine.getSnapshot().working).id;
  server.failNext = { ok: false, status: 0, error: { code: 'network_error', message: '' } };
  engine.apply(ops.updateBlockProps(id, { level: 'h2', text: 'kept' }));
  await sched.runAll(); await engine.busy;
  assert.equal(engine.getSnapshot().status, STATUS.ERROR);
  assert.equal(engine.getSnapshot().pending.length, 1, 'edit kept for retry');
  assert.equal(sched.pending()[0].ms, 2000, 'retry backoff scheduled');
  await sched.runAll(); await engine.busy;
  assert.equal(engine.getSnapshot().status, STATUS.SAVED);
  assert.equal(firstBlock(server.currentDoc).props.text, 'kept');
});

test('explicit save flushes as a manual revision', async () => {
  const { engine, server } = setup();
  const id = firstBlock(engine.getSnapshot().working).id;
  engine.apply(ops.updateBlockProps(id, { level: 'h2', text: 'manual' }));
  assert.equal(await engine.save(), true);
  assert.equal(server.calls[0][1].revision_kind, 'manual');
  assert.equal(engine.getSnapshot().pending.length, 0);
});

test('autosave of an unchanged document is deduplicated and records no undo step', async () => {
  const { engine, server, sched } = setup();
  const b = firstBlock(engine.getSnapshot().working);
  const rev = server.currentId;
  engine.apply(ops.updateBlockProps(b.id, { ...b.props }));
  await sched.runAll(); await engine.busy;
  assert.equal(server.currentId, rev, 'no new revision');
  assert.equal(engine.getSnapshot().undo.length, 0);
});

test('undo / redo are server rollbacks to immutable revisions (no documents stored in the browser)', async () => {
  const { engine, server, sched } = setup();
  const id = firstBlock(engine.getSnapshot().working).id;
  const r0 = server.currentId;
  engine.apply(ops.updateBlockProps(id, { level: 'h2', text: 'v1' }));
  await sched.runAll(); await engine.busy;
  const r1 = server.currentId;
  const entry = engine.getSnapshot().undo[0];
  assert.deepEqual({ before: entry.before, after: entry.after }, { before: r0, after: r1 });
  assert.ok(!('doc' in entry) && !('document' in entry), 'undo entries hold revision ids only');

  assert.equal(await engine.undo(), true);
  assert.deepEqual(server.calls.at(-1), ['rollback', { page_id: 1, target_revision_id: r0, expected_revision_id: r1 }]);
  assert.equal(firstBlock(engine.getSnapshot().working).props.text, 'Hello');
  assert.equal(engine.getSnapshot().redo.length, 1);

  const afterUndo = server.currentId;
  assert.equal(await engine.redo(), true);
  assert.deepEqual(server.calls.at(-1), ['rollback', { page_id: 1, target_revision_id: r1, expected_revision_id: afterUndo }]);
  assert.equal(firstBlock(engine.getSnapshot().working).props.text, 'v1');
});

test('publish drains pending edits first and publishes the exact revision it saw', async () => {
  const { engine, server } = setup();
  const id = firstBlock(engine.getSnapshot().working).id;
  engine.apply(ops.updateBlockProps(id, { level: 'h2', text: 'to publish' }));
  assert.equal(await engine.publish(), true);
  const kinds = server.calls.map((c) => c[0]);
  assert.deepEqual(kinds, ['operations', 'publish']);
  const savedRev = server.calls[1][1].expected_revision_id;
  assert.ok(Number.isInteger(savedRev));
  assert.equal(engine.getSnapshot().revision.id, server.currentId);
});

test('remove, move and section reorder are canonical operations and survive a reload from the server', async () => {
  const d = doc([section([heading('A'), heading('B'), container([heading('C')])], 'S1'), section([], 'S2')]);
  const { engine, server, sched } = setup(d);
  const w = engine.getSnapshot().working;
  const [a, b, c] = w.sections[0].blocks;
  const [s1, s2] = w.sections;
  engine.apply(ops.moveBlock(b.id, c.id, 0));          // move B into the container
  await sched.runAll(); await engine.busy;
  engine.apply(ops.removeBlock(a.id));                 // remove A
  await sched.runAll(); await engine.busy;
  engine.apply(ops.moveSection(s2.id, 0));             // reorder sections
  await sched.runAll(); await engine.busy;

  // A fresh editor built ONLY from the server reproduces the same structure.
  const reloaded = new SyncEngine({ transport: server.transport, pageId: 1, schedule: sched.schedule, cancel: sched.cancel });
  reloaded.load({}, manifest);
  await reloaded.reloadFromServer();
  const r = reloaded.getSnapshot().working;
  assert.deepEqual(r.sections.map((s) => s.id), [s2.id, s1.id]);
  assert.equal(findNode(r, a.id), null);
  assert.equal(findNode(r, b.id).parentId, c.id);
  assert.deepEqual(r, engine.getSnapshot().working);
  assert.ok(!nodeIds(r).has(a.id));
});
