import { test } from 'node:test';
import assert from 'node:assert/strict';
import { block, container, doc, heading, manifest, section } from './helpers.mjs';
import {
  MAX_CLIPBOARD_BYTES, buildEnvelope, clearMemory, parseEnvelope, planPaste, readClipboard, regenerateIds, writeClipboard,
} from '../src/core/clipboard.mjs';
import { canvasMenuItems, pasteState, rowFor } from '../src/core/rowMenu.mjs';
import { lockIndex } from '../src/core/layerLock.mjs';
import { applyLocal, OPS, insertBlock } from '../src/core/operations.mjs';

const ids = (node) => [node.id, ...[...(node.children || []), ...(node.blocks || [])].flatMap(ids)];
const blockEnv = (b) => buildEnvelope('block', b);
const text = (env) => JSON.stringify(env);

test('copy keeps the tree and drops every id and every lock', () => {
  const inner = heading('Hi');
  inner.metadata = { locked: true, label: 'Title' };
  const box = container([inner]);
  const env = blockEnv(box);
  assert.equal(env.kohevoStudio, 1);
  assert.equal(env.kind, 'block');
  assert.equal(env.node.type, 'core.container');
  assert.equal(env.node.children[0].props.text, 'Hi');
  assert.deepEqual(env.node.children[0].metadata, { label: 'Title' });
  assert.ok(!text(env).includes('blk_'), 'no id travels');
});

test('parse accepts what copy wrote and rejects foreign text without throwing', () => {
  const env = blockEnv(heading('A'));
  assert.equal(parseEnvelope(text(env)).ok, true);
  for (const bad of [null, undefined, 42, '', '   ', 'hello', '[1,2]', '{', '{"a":1}', '{"kohevoStudio":2,"kind":"block","node":{}}']) {
    const r = parseEnvelope(bad);
    assert.equal(r.ok, false, String(bad));
    assert.match(r.reasonKey, /^clip_/);
  }
  assert.equal(parseEnvelope('{"kohevoStudio":1,"kind":"page","node":{}}').reasonKey, 'clip_invalid');
  assert.equal(parseEnvelope('{"kohevoStudio":1,"kind":"block","node":{"type":"<script>"}}').reasonKey, 'clip_invalid');
  assert.equal(parseEnvelope('{"kohevoStudio":1,"kind":"block","node":{"type":"core.text","props":"x"}}').reasonKey, 'clip_invalid');
  assert.equal(parseEnvelope('{"kohevoStudio":1,"kind":"block","node":{"type":"core.text","children":{}}}').reasonKey, 'clip_invalid');
});

test('parse caps size, node count and nesting', () => {
  const big = text(blockEnv(block('core.text', { text: 'x'.repeat(MAX_CLIPBOARD_BYTES) })));
  assert.equal(parseEnvelope(big).reasonKey, 'clip_too_big');
  const many = { type: 'core.container', children: Array.from({ length: 700 }, () => ({ type: 'core.text' })) };
  assert.equal(parseEnvelope(JSON.stringify({ kohevoStudio: 1, kind: 'block', node: many })).reasonKey, 'clip_invalid');
  let deep = { type: 'core.text' };
  for (let i = 0; i < 20; i += 1) deep = { type: 'core.container', children: [deep] };
  assert.equal(parseEnvelope(JSON.stringify({ kohevoStudio: 1, kind: 'block', node: deep })).reasonKey, 'clip_invalid');
});

test('parse keeps only known keys and strips prototype poison', () => {
  const raw = '{"kohevoStudio":1,"kind":"block","node":{"type":"core.text","id":"blk_x","evil":1,"props":{"__proto__":{"polluted":true},"text":"ok"}}}';
  const r = parseEnvelope(raw);
  assert.equal(r.ok, true);
  assert.equal(r.envelope.node.evil, undefined);
  assert.equal(r.envelope.node.id, undefined);
  assert.equal(r.envelope.node.props.text, 'ok');
  assert.equal({}.polluted, undefined);
  assert.equal(Object.keys(r.envelope.node.props).includes('__proto__'), false);
});

test('a section copy carries its blocks, drops its lock, and keeps a global reference', () => {
  const s = section([heading('A'), container([heading('B')])], 'Hero');
  s.locked = true;
  const env = buildEnvelope('section', s);
  assert.equal(env.node.label, 'Hero');
  assert.equal(env.node.locked, undefined);
  assert.equal(env.node.blocks.length, 2);
  const g = section([], 'Shared');
  g.global_ref = 'footer-cta';
  assert.equal(buildEnvelope('section', g).node.global_ref, 'footer-cta');
});

test('regenerateIds gives every section and block a new provisional id, every time', () => {
  const env = buildEnvelope('section', section([heading('A'), container([heading('B')])]));
  const a = regenerateIds(env.node, 'section');
  const b = regenerateIds(env.node, 'section');
  const all = [...ids(a), ...ids(b)];
  assert.equal(all.length, 8);
  assert.equal(new Set(all).size, 8);
  assert.ok(all.every((x) => x.startsWith('tmp_')));
  assert.equal(env.node.id, undefined, 'the source is untouched');
});

const page = () => {
  const h = heading('A');
  const box = container([heading('In')]);
  const s1 = section([h, box], 'One');
  const s2 = section([heading('Two')], 'Two');
  return { d: doc([s1, s2]), h, box, s1, s2 };
};

test('paste target: after a block, inside a container or section, or at the end of the page', () => {
  const { d, h, box, s1, s2 } = page();
  const env = blockEnv(heading('New'));
  const run = (targetId, mode) => planPaste({ doc: d, manifest, envelope: env, targetId, mode });
  assert.deepEqual(run(h.id, 'after'), { ok: true, kind: 'block', parentId: s1.id, index: 1 });
  assert.deepEqual(run(box.id, 'inside'), { ok: true, kind: 'block', parentId: box.id, index: 1 });
  assert.deepEqual(run(box.id, 'after'), { ok: true, kind: 'block', parentId: s1.id, index: 2 });
  assert.deepEqual(run(s1.id, 'inside'), { ok: true, kind: 'block', parentId: s1.id, index: 2 });
  assert.deepEqual(run(s1.id, 'after'), { ok: true, kind: 'block', parentId: s1.id, index: 2 });
  assert.deepEqual(run(null, 'after'), { ok: true, kind: 'block', parentId: s2.id, index: 1 });
  assert.deepEqual(run('gone', 'after'), { ok: true, kind: 'block', parentId: s2.id, index: 1 });
});

test('paste target: a block into an empty page asks for a new section', () => {
  const r = planPaste({ doc: doc([]), manifest, envelope: blockEnv(heading('x')), targetId: null });
  assert.deepEqual(r, { ok: true, kind: 'block', newSection: true, sectionIndex: 0 });
});

test('paste target: a section lands after the target section, whatever was selected', () => {
  const { d, h, s1 } = page();
  const env = buildEnvelope('section', section([heading('N')]));
  assert.deepEqual(planPaste({ doc: d, manifest, envelope: env, targetId: s1.id }), { ok: true, kind: 'section', index: 1 });
  assert.deepEqual(planPaste({ doc: d, manifest, envelope: env, targetId: h.id, mode: 'inside' }), { ok: true, kind: 'section', index: 1 });
  assert.deepEqual(planPaste({ doc: d, manifest, envelope: env, targetId: null }), { ok: true, kind: 'section', index: 2 });
});

test('paste obeys the same rules as an insert: locks, leaves, limits, unknown and unavailable blocks', () => {
  const { d, h, s1 } = page();
  const env = blockEnv(heading('New'));
  // a leaf cannot hold children
  assert.equal(planPaste({ doc: d, manifest, envelope: env, targetId: h.id, mode: 'inside' }).reasonKey, 'clip_not_allowed');
  // a locked section refuses
  s1.locked = true;
  assert.equal(planPaste({ doc: d, manifest, envelope: env, targetId: s1.id }).reasonKey, 'clip_locked');
  delete s1.locked;
  // a locked block's parent refuses insertion next to it
  h.metadata = { locked: true };
  assert.equal(planPaste({ doc: d, manifest, envelope: env, targetId: h.id, mode: 'after' }).ok, true, 'next to a locked block is its parent that counts');
  // unknown type
  const unknown = { kohevoStudio: 1, kind: 'block', node: { type: 'vendor.nope', children: [] } };
  assert.equal(planPaste({ doc: d, manifest, envelope: unknown, targetId: s1.id }).reasonKey, 'clip_unavailable');
  // a block the site is not entitled to
  const gated = { ...manifest, blocks: manifest.blocks.map((b) => (b.type === 'core.heading' ? { ...b, locked: true } : b)) };
  assert.equal(planPaste({ doc: d, manifest: gated, envelope: env, targetId: s1.id }).reasonKey, 'clip_unavailable');
  // block limit
  const tight = { ...manifest, limits: { ...manifest.limits, max_blocks: 4 } };
  assert.equal(planPaste({ doc: d, manifest: tight, envelope: env, targetId: s1.id }).reasonKey, 'clip_blocks_limit');
  // section limit
  const one = { ...manifest, limits: { ...manifest.limits, max_sections: 2 } };
  assert.equal(planPaste({ doc: d, manifest: one, envelope: buildEnvelope('section', section([])), targetId: s1.id }).reasonKey, 'clip_sections_limit');
  // a global (shared) section owns no blocks
  s1.global_ref = 'x';
  assert.equal(planPaste({ doc: d, manifest, envelope: env, targetId: s1.id }).reasonKey, 'clip_global');
});

test('paste refuses a subtree deeper than the nesting limit', () => {
  let deep = heading('leaf');
  for (let i = 0; i < 5; i += 1) deep = container([deep]);
  const d = doc([section([container([])])]);
  const inner = d.sections[0].blocks[0];
  const r = planPaste({ doc: d, manifest, envelope: blockEnv(deep), targetId: inner.id, mode: 'inside' });
  assert.equal(r.reasonKey, 'clip_not_allowed');
  assert.equal(planPaste({ doc: d, manifest, envelope: blockEnv(deep), targetId: d.sections[0].id }).ok, true);
});

test('the pasted block lands in the local document with fresh ids all the way down', () => {
  const { d, s2 } = page();
  const env = blockEnv(container([heading('In')]));
  const fresh = regenerateIds(env.node, 'block');
  const { id, ...payload } = fresh;
  const next = applyLocal(d, insertBlock(s2.id, 1, payload), { manifest, provisionalId: id });
  const pasted = next.sections[1].blocks[1];
  assert.equal(pasted.id, id);
  assert.equal(pasted.children.length, 1);
  assert.equal(pasted.children[0].props.text, 'In');
  assert.notEqual(pasted.children[0].id, env.node.children[0].id);
  assert.ok(pasted.children[0].id.startsWith('tmp_'));
  assert.equal(OPS.INSERT_BLOCK, 'insert_block');
});

test('menu items: the canvas menu offers edit, duplicate, copy, paste, cut, lock, hide and delete', () => {
  const { d, h } = page();
  const row = rowFor(d, h.id);
  const items = canvasMenuItems({ row, doc: d, manifest, locks: lockIndex(d), paste: pasteState({ row, doc: d, manifest, envelope: null }) });
  assert.deepEqual(items.map((i) => i.key), ['edit', 'duplicate', 'copy', 'paste_after', 'cut', 'lock', 'hide', 'delete']);
  assert.ok(items.every((i) => !i.disabled));
});

test('menu items: a container also offers Paste inside; a section too', () => {
  const { d, box, s1 } = page();
  for (const id of [box.id, s1.id]) {
    const row = rowFor(d, id);
    const keys = canvasMenuItems({ row, doc: d, manifest, locks: lockIndex(d), paste: pasteState({ row, doc: d, manifest, envelope: null }) }).map((i) => i.key);
    assert.ok(keys.includes('paste_inside'), id);
  }
});

test('menu items: a locked layer cannot be cut or deleted, a child of a locked parent likewise', () => {
  const { d, h, box, s1 } = page();
  h.metadata = { locked: true };
  const at = (id, envelope = null) => {
    const row = rowFor(d, id);
    const items = canvasMenuItems({ row, doc: d, manifest, locks: lockIndex(d), paste: pasteState({ row, doc: d, manifest, envelope }) });
    return Object.fromEntries(items.map((i) => [i.key, i]));
  };
  assert.equal(at(h.id).cut.disabled, true);
  assert.equal(at(h.id).delete.disabled, true);
  assert.equal(at(h.id).unlock.disabled, undefined);
  assert.equal(at(h.id).copy.disabled, undefined);
  assert.equal(at(s1.id).cut.disabled, true, 'a section holding a locked block cannot be cut');
  assert.equal(at(box.id).cut.disabled, false);
  delete h.metadata;
  s1.locked = true;
  assert.equal(at(box.id).cut.disabled, true);
  assert.equal(at(box.id, blockEnv(heading('x'))).paste_inside.disabled, true, 'nothing can be pasted into a locked parent once the clipboard is known');
});

test('menu items: with a known clipboard, Paste is disabled with the reason', () => {
  const { d, h } = page();
  const row = rowFor(d, h.id);
  const lonely = { ...manifest, limits: { ...manifest.limits, max_blocks: 3 } };
  const state = pasteState({ row, doc: d, manifest: lonely, envelope: blockEnv(heading('x')) });
  assert.deepEqual(state.after, { disabled: true, reasonKey: 'clip_blocks_limit' });
  assert.equal(state.inside, null, 'a leaf block has no Paste inside');
});

// ── the clipboard itself ────────────────────────────────────────────────────

const fakeNav = ({ readText, writeText } = {}) => ({ clipboard: { readText, writeText } });

test('write keeps an in-memory copy when the browser refuses the clipboard API', async () => {
  clearMemory();
  const env = blockEnv(heading('Mem'));
  const refused = fakeNav({ writeText: async () => { throw new Error('denied'); }, readText: async () => { throw new Error('denied'); } });
  const w = await writeClipboard(env, refused);
  assert.deepEqual(w, { ok: true, system: false });
  const r = await readClipboard(refused);
  assert.equal(r.ok, true);
  assert.equal(r.envelope.node.props.text, 'Mem');
  assert.equal((await readClipboard(null)).ok, true, 'no clipboard API at all');
});

test('read prefers the system clipboard (a copy from another tab) and never mixes in a stale memory copy', async () => {
  clearMemory();
  await writeClipboard(blockEnv(heading('Old')), fakeNav({ writeText: async () => {} }));
  const other = text(blockEnv(heading('FromOtherTab')));
  const fromSystem = await readClipboard(fakeNav({ readText: async () => other }));
  assert.equal(fromSystem.envelope.node.props.text, 'FromOtherTab');
  const foreign = await readClipboard(fakeNav({ readText: async () => 'just some words' }));
  assert.deepEqual(foreign, { ok: false, reasonKey: 'clip_foreign' });
  const hostile = await readClipboard(fakeNav({ readText: async () => '{"kohevoStudio":1,"kind":"block","node":{"type":"x y"}}' }));
  assert.equal(hostile.reasonKey, 'clip_invalid');
  const blank = await readClipboard(fakeNav({ readText: async () => '' }));
  assert.equal(blank.envelope.node.props.text, 'Old', 'an empty system clipboard falls back to memory');
});

test('read explains itself when there is nothing at all', async () => {
  clearMemory();
  assert.equal((await readClipboard(fakeNav({ readText: async () => { throw new Error('denied'); } }))).reasonKey, 'clip_unreadable');
  assert.equal((await readClipboard(fakeNav({ readText: async () => '' }))).reasonKey, 'clip_empty');
});

test('write refuses content over the size cap', async () => {
  clearMemory();
  const env = { kohevoStudio: 1, kind: 'block', node: { type: 'core.text', props: { text: 'x'.repeat(MAX_CLIPBOARD_BYTES) } } };
  assert.deepEqual(await writeClipboard(env, fakeNav({ writeText: async () => {} })), { ok: false, reasonKey: 'clip_too_big' });
});
