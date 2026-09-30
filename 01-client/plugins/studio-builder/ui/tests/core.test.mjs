// Document rules, operations, field model, viewports, rich text, canvas bridge,
// API client, edit lock — the framework-free core of the builder.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as d from '../src/core/doc.mjs';
import * as ops from '../src/core/operations.mjs';
import * as fields from '../src/core/fields.mjs';
import { VIEWPORTS, breakpointForWidth, resolveResponsive, BREAKPOINTS } from '../src/core/viewport.mjs';
import { toAllowedHtml } from '../src/core/richtext.mjs';
import { attachCanvas, nodeElementFrom, markSelected } from '../src/core/canvas.mjs';
import { createTransport } from '../src/core/api.mjs';
import { EditLock } from '../src/core/lock.mjs';
import { t, errorMessage } from '../src/core/messages.mjs';
import { manifest, doc, section, heading, container, block } from './helpers.mjs';

// ── doc.mjs ───────────────────────────────────────────────────────────────

test('tree: find, walk, ids, depth, labels', () => {
  const inner = heading('Inner');
  const c = container([inner]);
  const s = section([heading('Top'), c]);
  const x = doc([s]);
  assert.equal(d.findNode(x, inner.id).parentId, c.id);
  assert.equal(d.findNode(x, inner.id).depth, 2);
  assert.equal(d.findNode(x, s.id).kind, 'section');
  assert.equal(d.countBlocks(x), 3);
  assert.equal(d.nodeIds(x).size, 4);
  assert.equal(d.nodeLabel(inner, manifest, 'block'), 'Heading: Inner');
  assert.deepEqual(d.asObject([]), {}, 'PHP empty-object encoding ([]) reads as {}');
});

test('structure rules mirror the server: children only in containers, no cycles, depth limit', () => {
  const leaf = heading('Leaf');
  const c1 = container([]);
  const c2 = container([container([container([container([])])])]); // depths 1..4 (the maximum)
  const s = section([leaf, c1, c2]);
  const x = doc([s]);
  assert.equal(d.canMoveBlock(x, manifest, leaf.id, c1.id), true, 'into a container');
  assert.equal(d.canMoveBlock(x, manifest, c1.id, leaf.id), false, 'a heading does not allow children');
  assert.equal(d.canMoveBlock(x, manifest, c2.id, c2.children[0].id), false, 'never into its own descendant');
  assert.equal(d.canMoveBlock(x, manifest, c2.id, c1.id), false, 'depth would exceed max_nesting_depth (4)');
  const third = c2.children[0].children[0];
  assert.equal(d.canInsertBlock(x, manifest, third.id, 'core.heading'), true, 'a child at depth 4 is allowed');
  const deepest = third.children[0];
  assert.equal(d.canInsertBlock(x, manifest, deepest.id, 'core.heading'), false, 'a child at depth 5 is not');
  assert.equal(d.canInsertBlock(x, manifest, s.id, 'unknown.block'), false, 'unregistered types are never offered');
});

test('keyboard move targets: siblings, across sections, out of / into containers', () => {
  const a = heading('A'); const b = heading('B'); const inC = heading('In');
  const c = container([inC]);
  const s1 = section([a, b, c]); const s2 = section([]);
  const x = doc([s1, s2]);
  assert.deepEqual(d.blockMoveTarget(x, manifest, b.id, 'up'), { parentId: s1.id, index: 0 });
  assert.deepEqual(d.blockMoveTarget(x, manifest, a.id, 'up'), null, 'first block of first section cannot go up');
  assert.deepEqual(d.blockMoveTarget(x, manifest, c.id, 'down'), { parentId: s2.id, index: 0 }, 'crosses into the next section');
  assert.deepEqual(d.blockMoveTarget(x, manifest, inC.id, 'up'), { parentId: s1.id, index: 2 }, 'steps out before its container');
  assert.deepEqual(d.blockOutdentTarget(x, manifest, inC.id), { parentId: s1.id, index: 3 });
  assert.deepEqual(d.blockIndentTarget(x, manifest, b.id), null, 'previous sibling is not a container');
  const y = doc([section([container([]), heading('Z')])]);
  const z = y.sections[0].blocks[1];
  assert.deepEqual(d.blockIndentTarget(y, manifest, z.id), { parentId: y.sections[0].blocks[0].id, index: 0 });
});

test('insertion point follows the selection', () => {
  const a = heading('A'); const c = container([]);
  const s = section([a, c]);
  const x = doc([s]);
  assert.deepEqual(d.insertionPoint(x, manifest, a.id, 'core.heading'), { parentId: s.id, index: 1 });
  assert.deepEqual(d.insertionPoint(x, manifest, c.id, 'core.heading'), { parentId: c.id, index: 0 });
  assert.deepEqual(d.insertionPoint(x, manifest, s.id, 'core.heading'), { parentId: s.id, index: 2 });
  assert.equal(d.insertionPoint(doc([]), manifest, null, 'core.heading'), null, 'an empty page needs a section first');
});

// ── operations.mjs ───────────────────────────────────────────────────────

test('operations use the canonical {op, payload} vocabulary', () => {
  assert.deepEqual(ops.moveBlock('blk_1', 'sec_1', 2), { op: 'move_block', payload: { block_id: 'blk_1', parent_id: 'sec_1', index: 2 } });
  assert.deepEqual(ops.insertBlock('sec_1', 0, { type: 'core.heading' }), { op: 'insert_block', payload: { parent_id: 'sec_1', index: 0, block: { type: 'core.heading' } } });
  assert.deepEqual(ops.moveSection('sec_1', 3), { op: 'move_section', payload: { section_id: 'sec_1', to_index: 3 } });
  const serverOps = ['update_settings', 'update_seo', 'update_template', 'insert_section', 'remove_section', 'move_section', 'update_section_layout',
    'update_section_visibility', 'insert_block', 'remove_block', 'move_block', 'update_block_props', 'update_block_style', 'update_block_visibility', 'update_block_bindings'];
  for (const name of Object.values(ops.OPS)) assert.ok(serverOps.includes(name), `${name} is a server DocumentOperation`);
});

test('local apply: every structural op, with structural sharing', () => {
  const a = heading('A'); const b = heading('B'); const c = container([]);
  const s1 = section([a, b, c]); const s2 = section([heading('Other')]);
  const x = doc([s1, s2]);
  const moved = ops.applyLocal(x, ops.moveBlock(a.id, c.id, 0), { manifest });
  assert.equal(d.findNode(moved, a.id).parentId, c.id);
  assert.equal(moved.sections[1], x.sections[1], 'untouched section keeps its identity');
  const removed = ops.applyLocal(x, ops.removeBlock(b.id), { manifest });
  assert.equal(d.findNode(removed, b.id), null);
  const inserted = ops.applyLocal(x, ops.insertBlock(s2.id, 0, { type: 'core.button' }), { manifest, provisionalId: 'tmp_x' });
  const ins = d.findNode(inserted, 'tmp_x').node;
  assert.equal(ins.type, 'core.button');
  assert.equal(ins.version, 1);
  const secs = ops.applyLocal(x, ops.moveSection(s2.id, 0), { manifest });
  assert.deepEqual(secs.sections.map((s) => s.id), [s2.id, s1.id]);
  const newSec = ops.applyLocal(x, ops.insertSection(1), { provisionalId: 'tmp_s' });
  assert.equal(newSec.sections[1].id, 'tmp_s');
  assert.deepEqual(newSec.sections[1].layout, ops.DEFAULT_SECTION_LAYOUT);
  assert.throws(() => ops.applyLocal(x, ops.removeBlock('blk_missing')), /No node/);
  assert.equal(x.sections[0].blocks.length, 3, 'the input document is never mutated');
});

test('coalescing and provisional id remapping', () => {
  let q = [];
  q = ops.enqueueCoalesced(q, { op: ops.updateBlockProps('b1', { t: 1 }) });
  q = ops.enqueueCoalesced(q, { op: ops.updateBlockProps('b1', { t: 2 }) });
  q = ops.enqueueCoalesced(q, { op: ops.updateBlockProps('b2', { t: 3 }) });
  q = ops.enqueueCoalesced(q, { op: ops.updateSeo({ title: 'a' }) });
  q = ops.enqueueCoalesced(q, { op: ops.updateSeo({ description: 'b' }) });
  assert.equal(q.length, 3);
  assert.deepEqual(q[0].op.payload.props, { t: 2 });
  assert.deepEqual(q[2].op.payload.seo, { title: 'a', description: 'b' }, 'SEO patches merge like the server');
  const remapped = ops.remapIds(ops.moveBlock('tmp_a', 'tmp_b', 0), new Map([['tmp_a', 'blk_1'], ['tmp_b', 'blk_2']]));
  assert.deepEqual(remapped.payload, { block_id: 'blk_1', parent_id: 'blk_2', index: 0 });
});

// ── fields.mjs ───────────────────────────────────────────────────────────

test('every canonical field type maps to a control', () => {
  for (const type of ['string', 'text', 'rich_text', 'number', 'boolean', 'enum', 'url', 'media_ref', 'token_ref', 'link', 'repeater', 'object']) {
    assert.ok(fields.controlFor({ type }), `${type} has a control`);
  }
  assert.equal(fields.controlFor({ type: 'php_eval' }), null, 'unknown types get no control');
});

test('coercion and advisory hints follow the FieldSchema constraints', () => {
  assert.equal(fields.coerce({ type: 'number', integer_only: true }, '3.7'), 3);
  assert.equal(fields.coerce({ type: 'number' }, ''), null);
  assert.equal(fields.coerce({ type: 'string' }, 'a\nb'), 'a b', 'single-line strings never carry newlines');
  assert.equal(fields.hint({ type: 'string', required: true }, ''), 'field_required');
  assert.equal(fields.hint({ type: 'string', max_length: 3 }, 'abcd'), 'field_too_long');
  assert.equal(fields.hint({ type: 'number', min: 1, max: 4 }, 5), 'field_too_large');
  assert.equal(fields.hint({ type: 'url' }, 'javascript:alert(1)'), 'field_url');
  assert.equal(fields.hint({ type: 'url' }, '//evil.example'), 'field_url');
  assert.equal(fields.hint({ type: 'url' }, '/about'), null);
  assert.equal(fields.hint({ type: 'link' }, { label: 'Go', href: 'https://example.com' }), null);
  assert.equal(fields.hint({ type: 'link' }, { label: '', href: '/x' }), 'field_link_label');
  assert.equal(fields.hint({ type: 'media_ref', required: true }, { media_id: 0, alt: '' }), 'field_media');
});

test('insert setup: only required fields without a valid default (real manifest)', () => {
  const def = (type) => d.blockDefinition(manifest, type);
  assert.deepEqual(fields.setupFields(def('core.image')).map((f) => f.key), ['media']);
  assert.deepEqual(fields.setupFields(def('core.button')).map((f) => f.key), ['link']);
  assert.deepEqual(fields.setupFields(def('core.heading')), []);
  const booking = fields.defaultBindings(def('booking.services'), manifest);
  assert.deepEqual(booking.bindings, { items: { provider: 'booking.services' } });
  const form = fields.defaultBindings(def('forms.form_card'), manifest);
  assert.deepEqual(form.bindings, {});
  assert.equal(form.needsParams[0].slot, 'form');
  assert.deepEqual(form.needsParams[0].params.map((p) => p.key), ['slug']);
});

test('manifest is transport-safe data (no class names, paths or code)', () => {
  const text = JSON.stringify(manifest);
  for (const bad of ['Slate\\\\', '.php', 'function', '<?', 'SELECT ', 'Closure', '/Users/']) {
    assert.ok(!text.includes(bad), `manifest must not contain ${bad}`);
  }
});

// ── viewport.mjs ─────────────────────────────────────────────────────────

test('viewports use the canonical breakpoints and renderer thresholds', () => {
  assert.deepEqual(BREAKPOINTS, manifest.vocabulary.breakpoints);
  for (const v of VIEWPORTS) assert.equal(breakpointForWidth(v.width), v.breakpoint, `${v.key} renders at ${v.breakpoint}`);
  assert.equal(breakpointForWidth(639), 'base');
  assert.equal(breakpointForWidth(640), 'sm');
  assert.equal(breakpointForWidth(1023), 'md');
  assert.equal(resolveResponsive({ base: 1, md: 3 }, 'sm'), 1);
  assert.equal(resolveResponsive({ base: 1, md: 3 }, 'lg'), 3);
});

// ── richtext.mjs ─────────────────────────────────────────────────────────

test('rich text output is reduced to the server allowlist', () => {
  const lexical = '<p class="sbx-rt-p" dir="ltr"><b><strong class="sbx-rt-b" style="white-space: pre-wrap;">Bold</strong></b><span style="white-space: pre-wrap;"> and </span><a href="https://example.com" class="sbx-rt-a" target="_blank"><span>link</span></a></p><ul class="sbx-rt-ul"><li value="1" class="sbx-rt-li"><span>one</span></li></ul>';
  assert.equal(toAllowedHtml(lexical), '<p><strong>Bold</strong> and <a href="https://example.com" target="_blank" rel="noopener noreferrer">link</a></p><ul><li>one</li></ul>');
  assert.equal(toAllowedHtml('<p onclick="x()">a<script>alert(1)</script><img src=x onerror=y>b</p>'), '<p>ab</p>');
  assert.equal(toAllowedHtml('<a href="javascript:alert(1)">x</a>'), '<a>x</a>');
  assert.equal(toAllowedHtml('<style>p{}</style><iframe src="//x"></iframe>'), '<p></p>');
  assert.equal(toAllowedHtml('<p>unclosed <em>em'), '<p>unclosed <em>em</em></p>');
});

// ── canvas.mjs (fake DOM) ────────────────────────────────────────────────

function fakeEl(attrs = {}, parent = null) {
  const classes = new Set();
  return {
    nodeType: 1, parentElement: parent, attrs,
    getAttribute: (n) => (n in attrs ? attrs[n] : null),
    classList: { add: (c) => classes.add(c), remove: (c) => classes.delete(c), has: (c) => classes.has(c) },
    scrollIntoView() {},
  };
}

test('canvas: clicks resolve to data-sb-node ids and never navigate', () => {
  const blockEl = fakeEl({ 'data-sb-node': 'blk_1', 'data-sb-type': 'core.button' });
  const link = fakeEl({ href: '/elsewhere' }, blockEl);
  assert.equal(nodeElementFrom(link), blockEl);
  const listeners = {};
  const fakeDoc = {
    body: {}, head: { appendChild() {} },
    getElementById: () => null,
    createElement: () => ({}),
    addEventListener: (type, fn) => { listeners[type] = fn; },
    removeEventListener: (type) => { delete listeners[type]; },
  };
  const picked = [];
  const detach = attachCanvas(fakeDoc, { onSelect: (id, type) => picked.push([id, type]) });
  let prevented = false;
  listeners.click({ target: link, preventDefault: () => { prevented = true; }, stopPropagation() {} });
  assert.equal(prevented, true, 'links inside the canvas never navigate');
  assert.deepEqual(picked, [['blk_1', 'core.button']]);
  assert.ok(listeners.submit, 'form submission is blocked too');
  detach();
  assert.equal(listeners.click, undefined);

  const selected = [];
  const target = fakeEl({ 'data-sb-node': 'blk_2' });
  markSelected({ querySelectorAll: () => selected, querySelector: () => target }, 'blk_2', { scroll: false });
  assert.ok(target.classList.has('sbx-selected'));
});

// ── api.mjs ──────────────────────────────────────────────────────────────

test('API client: CSRF header on commands, safe error normalization, never throws', async () => {
  const seen = [];
  const fetchImpl = async (url, init) => {
    seen.push([url, init]);
    if (url.includes('action=publish')) return { status: 409, json: async () => ({ ok: false, error: { code: 'concurrency_conflict', message: 'x', details: { current_revision_id: 9 } } }) };
    if (url.includes('action=status')) return { status: 500, json: async () => { throw new Error('html'); } };
    return { status: 200, json: async () => ({ ok: true, data: { hello: 1 } }) };
  };
  const api = createTransport({ apiUrl: 'http://app.test/plugins/studio-builder/admin/api.php', csrfToken: 'tok123', fetchImpl });
  const ok = await api.operations({ page_id: 1, expected_revision_id: 2, revision_kind: 'autosave', operations: [] });
  assert.deepEqual(ok, { ok: true, status: 200, data: { hello: 1 } });
  const [url, init] = seen[0];
  assert.match(url, /action=operations/);
  assert.equal(init.method, 'POST');
  assert.equal(init.headers['X-CSRF-Token'], 'tok123');
  assert.equal(init.headers['Content-Type'], 'application/json');
  assert.equal(init.credentials, 'same-origin');
  const conflict = await api.publish({ page_id: 1, expected_revision_id: 2 });
  assert.equal(conflict.error.code, 'concurrency_conflict');
  const broken = await api.status(1);
  assert.deepEqual(broken.error.code, 'server_error', 'non-JSON answers become a safe server_error');
  const down = await createTransport({ apiUrl: 'http://app.test/api.php', csrfToken: 't', fetchImpl: async () => { throw new TypeError('offline'); } }).document(1);
  assert.deepEqual(down, { ok: false, status: 0, error: { code: 'network_error', message: '' } });
  const [getUrl, getInit] = seen.find(([u]) => u.includes('action=status'));
  assert.equal(getInit.method, 'GET');
  assert.equal(getInit.headers['X-CSRF-Token'], undefined, 'queries carry no token');
  assert.match(getUrl, /page=1/);
});

test('error codes render as fixed human messages, never server text', () => {
  assert.equal(errorMessage({ code: 'concurrency_conflict', message: 'SQLSTATE[HY000] leak' }), t('error_concurrency_conflict'));
  assert.equal(errorMessage({ code: 'totally_unknown' }), t('error_server_error'));
});

// ── lock.mjs ─────────────────────────────────────────────────────────────

test('edit lock: advisory acquire, heartbeat with token, release', async () => {
  const calls = [];
  let tick = null;
  const transport = {
    lockAcquire: async (p) => { calls.push(['acquire', p]); return { ok: true, data: { lock: { held: true, lock_token: 'tok', other_editor: false } } }; },
    lockRefresh: async (p, tk) => { calls.push(['refresh', p, tk]); return { ok: true, data: { lock: { held: false, lock_token: null, other_editor: true } } }; },
    lockRelease: async (p, tk) => { calls.push(['release', p, tk]); return { ok: true, data: { released: true } }; },
  };
  const states = [];
  const lock = new EditLock({ transport, pageId: 7, onChange: (s) => states.push(s), setIntervalImpl: (fn) => { tick = fn; return 1; }, clearIntervalImpl: () => { tick = null; } });
  await lock.start();
  assert.deepEqual(states.at(-1), { held: true, otherEditor: false });
  await tick();
  assert.deepEqual(calls[1], ['refresh', 7, 'tok']);
  assert.deepEqual(states.at(-1), { held: false, otherEditor: true }, 'someone else took the page: warn, never block');
  await tick();
  assert.equal(calls[2][0], 'acquire', 'a lost lock is re-acquired when free');
  lock.token = 'tok2';
  await lock.stop();
  assert.deepEqual(calls.at(-1), ['release', 7, 'tok2']);
  assert.equal(tick, null);
});

void block;
