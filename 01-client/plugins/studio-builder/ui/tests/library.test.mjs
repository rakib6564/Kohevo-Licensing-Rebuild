// Phase 6 — template library / global component helpers, live-reference
// rules in the document helpers, and the sync engine's one-step server
// commands (apply_template / insert_template / detach / create_component).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as d from '../src/core/doc.mjs';
import * as ops from '../src/core/operations.mjs';
import * as lib from '../src/core/library.mjs';
import { SyncEngine, STATUS } from '../src/core/sync.mjs';
import { manifest, doc, topContainer, heading, container, manualScheduler, fakeServer } from './helpers.mjs';

const REF = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

function globalContainer(ref = REF, label = 'Shared') {
  return { ...topContainer([], label), global_ref: ref };
}

test('library: slugs, template groups per page type, save scopes follow the selection', () => {
  assert.equal(lib.slugify('  Héllo World! 2026 '), 'hello-world-2026');
  assert.equal(lib.slugify('###'), '');
  assert.deepEqual(lib.templateGroupsFor('page').map((g) => g.type), ['page_template', 'container_preset', 'block_preset']);
  assert.deepEqual(lib.templateGroupsFor('header_partial').map((g) => g.type), ['container_preset', 'block_preset', 'header_preset']);
  assert.deepEqual(lib.templateGroupsFor('container_preset').map((g) => g.type), ['container_preset', 'block_preset']);

  const h = heading('A');
  const s = topContainer([h]);
  const g = globalContainer();
  const x = doc([s, g]);
  assert.deepEqual(lib.saveScopesFor(x, 'page', null).map((s) => s.type), ['page_template']);
  assert.deepEqual(lib.saveScopesFor(x, 'page', s.id).map((s) => s.type), ['page_template', 'container_preset']);
  assert.deepEqual(lib.saveScopesFor(x, 'page', h.id).map((s) => s.type), ['page_template', 'block_preset']);
  assert.deepEqual(lib.saveScopesFor(x, 'page', g.id).map((s) => s.type), ['page_template'], 'a live reference cannot be saved as a copy preset');
  assert.deepEqual(lib.saveScopesFor(x, 'footer_partial', null).map((s) => s.type), ['footer_preset']);

  const grouped = lib.groupTemplates([{ template_key: 'a', template_type: 'page_template' }, { template_key: 'b', template_type: 'block_preset' }, { template_key: 'c', template_type: 'header_preset' }], 'page');
  assert.deepEqual(grouped.map((g) => [g.type, g.items.length]), [['page_template', 1], ['container_preset', 0], ['block_preset', 1]], 'header presets are not offered on a page');
});

test('library: insertion targets and reference indexes follow the selection; block presets use the block insertion point', () => {
  const h = heading('A');
  const c = container([]);
  const s1 = topContainer([h, c]);
  const s2 = topContainer([]);
  const x = doc([s1, s2]);
  const ip = (dd, sel) => d.insertionPoint(dd, manifest, sel, 'core.container');
  assert.deepEqual(lib.insertTargetFor(x, { template_type: 'container_preset' }, s1.id, ip), { index: 1, parent_id: null });
  assert.deepEqual(lib.insertTargetFor(x, { template_type: 'container_preset' }, h.id, ip), { index: 1, parent_id: null }, 'after the Container of the selected block');
  assert.deepEqual(lib.insertTargetFor(x, { template_type: 'container_preset' }, null, ip), { index: 2, parent_id: null }, 'at the end when nothing is selected');
  assert.deepEqual(lib.insertTargetFor(x, { template_type: 'block_preset' }, c.id, ip), { index: 0, parent_id: c.id }, 'into the selected container');
  assert.deepEqual(lib.insertTargetFor(x, { template_type: 'block_preset' }, h.id, ip), { index: 1, parent_id: s1.id }, 'after the selected block');
  assert.equal(lib.insertTargetFor(doc([]), { template_type: 'block_preset' }, null, ip), null, 'an empty page needs a Container first');
  assert.equal(lib.referenceIndexFor(x, s1.id), 1);
  assert.equal(lib.referenceIndexFor(x, null), 2);
  assert.equal(lib.componentByRef([{ ref: REF, title: 'X' }], REF).title, 'X');
  assert.equal(lib.componentByRef([], REF), null);
  assert.equal(lib.isHexColor('#AbCdEf'), true);
  assert.equal(lib.isHexColor('red'), false);
});

test('doc: a global Container is labelled, owns no children, and never receives inserts or moves', () => {
  const h = heading('A');
  const local = topContainer([h]);
  const g = globalContainer();
  const x = doc([local, g]);
  assert.equal(d.isGlobalContainer(g), true);
  assert.equal(d.isGlobalContainer(local), false);
  assert.equal(d.nodeLabel(g, manifest), 'Shared (global)');
  assert.equal(d.canInsertBlock(x, manifest, g.id, 'core.heading'), false, 'a reference owns no blocks');
  assert.equal(d.canInsertBlock(x, manifest, local.id, 'core.heading'), true);
  assert.equal(d.canMoveBlock(x, manifest, h.id, g.id), false, 'nothing can be moved into a reference');
  assert.deepEqual(d.insertionPoint(x, manifest, g.id, 'core.heading'), { parentId: local.id, index: 1 }, 'inserting with a reference selected falls back to the last local Container');
  assert.equal(d.insertionPoint(doc([g]), manifest, null, 'core.heading'), null, 'a page of only references needs a local Container first');
  const op = ops.insertGlobalContainer(1, REF, 'Intro');
  assert.equal(op.op, 'insert_block');
  assert.equal(op.payload.parent_id, null);
  assert.equal(op.payload.index, 1);
  assert.equal(op.payload.block.type, 'core.container');
  assert.equal(op.payload.block.global_ref, REF);
  assert.equal(op.payload.block.metadata.label, 'Intro');
  assert.deepEqual(op.payload.block.children, []);
  const next = ops.applyLocal(x, op, { provisionalId: 'tmp_sec_1' });
  assert.equal(next.containers[1].global_ref, REF, 'the optimistic copy carries the reference');
  assert.deepEqual(next.containers[1].children, []);
});

// ── SyncEngine.command: one-step server rewrites ──────────────────────────

const serverApply = (dd, o) => ops.applyLocal(dd, o, { manifest, provisionalId: o.op === 'insert_block' ? 'blk_ffffffffffffffffffffffff' : undefined });

function setup(initial = doc([topContainer([heading('Hello')])])) {
  const server = fakeServer(initial, serverApply);
  const sched = manualScheduler();
  const events = [];
  const engine = new SyncEngine({ transport: server.transport, pageId: 1, debounceMs: 900, schedule: sched.schedule, cancel: sched.cancel, onEvent: (e) => events.push(e.type) });
  engine.load({ document: JSON.parse(JSON.stringify(server.currentDoc)), page: { id: 1 }, revision: { id: server.currentId } }, manifest);
  return { server, sched, engine, events };
}

/** A fake apply_template: the server replaces the document with a template and mints a revision, guarded by expected_revision_id. */
function fakeApplyTemplate(server, templateDoc) {
  return async (body) => {
    server.calls.push(['apply_template', { ...body }]);
    if (body.expected_revision_id !== server.currentId) {
      return { ok: false, status: 409, error: { code: 'concurrency_conflict', message: '', details: { current_revision_id: server.currentId, expected_revision_id: body.expected_revision_id } } };
    }
    server.externalEdit((dd) => { dd.containers = JSON.parse(JSON.stringify(templateDoc.containers)); });
    const snap = await server.transport.document();
    return { ok: true, status: 200, data: { ...snap.data, deduplicated: false } };
  };
}

test('command: drains pending edits, carries expected_revision_id, replaces the base with the server document and records one undo step', async () => {
  const { server, engine, events } = setup();
  const id = engine.getSnapshot().working.containers[0].children[0].id;
  engine.apply(ops.updateBlockProps(id, { level: 'h2', text: 'typed' }));
  const template = doc([topContainer([heading('From template')], 'T')]);
  const before = server.currentId;
  const ok = await engine.command((base) => fakeApplyTemplate(server, template)({ ...base, template_key: 'promo' }), { label: 'Template applied' });
  assert.equal(ok, true);
  const calls = server.calls.map((c) => c[0]);
  assert.deepEqual(calls, ['operations', 'apply_template'], 'pending edits were flushed first');
  const cmd = server.calls[1][1];
  assert.equal(cmd.page_id, 1);
  assert.equal(cmd.expected_revision_id, before + 1, 'the command states the revision produced by the flush');
  const s = engine.getSnapshot();
  assert.equal(s.status, STATUS.SAVED);
  assert.equal(s.working.containers[0].children[0].props.text, 'From template', 'the server document is the new base');
  assert.equal(s.revision.id, server.currentId);
  assert.equal(s.pending.length, 0);
  assert.deepEqual(s.undo.map((u) => u.label).slice(-1), ['Template applied']);
  assert.ok(events.includes('command'));
  // Undo of the command is a rollback to the revision before it.
  await engine.undo();
  assert.equal(engine.getSnapshot().working.containers[0].children[0].props.text, 'typed');
});

test('command: a stale revision enters the conflict state and rewrites nothing; nothing is applied locally first', async () => {
  const { server, engine } = setup();
  const template = doc([topContainer([heading('From template')], 'T')]);
  server.externalEdit((dd) => { dd.containers[0].children[0].props.text = 'someone else'; });
  const ok = await engine.command((base) => fakeApplyTemplate(server, template)({ ...base, template_key: 'promo' }), { label: 'x' });
  assert.equal(ok, false);
  const s = engine.getSnapshot();
  assert.equal(s.status, STATUS.CONFLICT);
  assert.equal(s.working.containers[0].children[0].props.text, 'Hello', 'the local view was not rewritten');
  assert.equal(server.currentDoc.containers[0].children[0].props.text, 'someone else', 'the newer server revision survived');
  assert.equal(await engine.command(() => Promise.resolve({ ok: true, status: 200, data: {} }), {}), false, 'no further command is sent while in conflict');
});

test('command: a command that returns no document (component created without a source Container) leaves the page untouched', async () => {
  const { engine } = setup();
  const ok = await engine.command(() => Promise.resolve({ ok: true, status: 201, data: { component: { id: 9 } } }), { label: 'created' });
  assert.equal(ok, true);
  assert.equal(engine.getSnapshot().status, STATUS.SAVED);
  assert.equal(engine.getSnapshot().undo.length, 0);
});

import { filterTemplates, mergeTemplates, templateAction } from '../src/core/library.mjs';
test('filterTemplates needs every word, in the name, description or block labels', () => {
  const items = [
    { name: 'Hero with image', description: 'big banner', summary: { block_labels: ['Heading', 'Button'] } },
    { name: 'Pricing table', description: '', summary: { block_labels: ['Heading'] } },
  ];
  assert.equal(filterTemplates(items, '').length, 2);
  assert.deepEqual(filterTemplates(items, 'hero BANNER').map((x) => x.name), ['Hero with image']);
  assert.deepEqual(filterTemplates(items, 'heading').length, 2);
  assert.equal(filterTemplates(items, 'nothing here').length, 0);
});

test('library: saving over a taken template key needs an explicit confirmation for that key (TI-05)', () => {
  const lib0 = [{ template_key: 'hero' }];
  assert.deepEqual(lib.templateOverwriteFor('fresh', lib0, null, null), { needsConfirm: false, overwrite: false }, 'a new key saves without asking');
  assert.deepEqual(lib.templateOverwriteFor('hero', lib0, null, null), { needsConfirm: true, overwrite: false }, 'a listed key asks first');
  assert.deepEqual(lib.templateOverwriteFor('hero', lib0, null, 'hero'), { needsConfirm: true, overwrite: true }, 'confirmed: overwrite is sent');
  assert.deepEqual(lib.templateOverwriteFor('hero-2', lib0, null, 'hero'), { needsConfirm: false, overwrite: false }, 'a confirmation never carries over to another key');
  assert.deepEqual(lib.templateOverwriteFor('late', lib0, 'late', null), { needsConfirm: true, overwrite: false }, 'a server template_exists for a key the library did not list also asks');
  assert.deepEqual(lib.templateOverwriteFor('', lib0, '', ''), { needsConfirm: false, overwrite: false });
});

test('templateAction: page, header and footer templates replace; Container and block presets are inserted', () => {
  assert.equal(templateAction({ template_type: 'page_template' }), 'apply');
  assert.equal(templateAction({ template_type: 'header_preset' }), 'apply', 'a header inserted into a header would stack two');
  assert.equal(templateAction({ template_type: 'footer_preset' }), 'apply');
  assert.equal(templateAction({ template_type: 'container_preset' }), 'insert');
  assert.equal(templateAction({ template_type: 'block_preset' }), 'insert');
  assert.equal(templateAction(null), 'insert');
});

test('mergeTemplates: the tenant\'s templates first, then built-in ones it does not already list', () => {
  const own = [{ template_key: 'mine', template_type: 'header_preset' }, { template_key: 'system-header-classic', template_type: 'header_preset', name: 'kept' }];
  const builtIn = [{ template_key: 'system-header-classic', name: 'dropped' }, { template_key: 'system-header-split' }, null];
  assert.deepEqual(mergeTemplates(own, builtIn).map((tpl) => tpl.template_key), ['mine', 'system-header-classic', 'system-header-split']);
  assert.equal(mergeTemplates(own, builtIn)[1].name, 'kept', 'the listed row wins');
  assert.deepEqual(mergeTemplates(undefined, undefined), []);
});
