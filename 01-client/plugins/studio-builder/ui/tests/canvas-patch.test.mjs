// Kohevo Studio builder — optimistic canvas patching (Phase 3).
//
// The canvas patches its DOM in place instead of reloading the frame. The whole
// safety argument rests on "patch only what is provable, reload otherwise", so
// most of these tests are about the REFUSALS — a patch that guesses is worse
// than a reload, because a reload cannot corrupt the document.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as patch from '../src/core/canvasPatch.mjs';

/**
 * A minimal DOM stand-in: exactly the surface `canvasPatch.mjs` touches, and
 * nothing more, so the tests cannot pass by accident against a richer document.
 */
function fakeEl({ classes = [], attrs = {}, style = {}, text = null, kids = [], tag = 'div' } = {}) {
  const classList = new Set(classes);
  const attrMap = { ...attrs };
  const styleMap = { ...style };
  // `children` exists because findTextHost() consults it to tell a wrapper with
  // element content apart from one whose text is a direct child node.
  const childList = kids.slice();
  return {
    tag,
    textContent: text,
    children: childList,
    classList: {
      add: (c) => classList.add(c),
      remove: (c) => classList.delete(c),
      contains: (c) => classList.has(c),
      has: (c) => classList.has(c),
    },
    style: {
      setProperty: (k, v) => { styleMap[k] = v; },
      removeProperty: (k) => { delete styleMap[k]; },
      // `Array.from(el.style)` must enumerate the custom properties we own.
      [Symbol.iterator]: function* iter() { yield* Object.keys(styleMap); },
    },
    setAttribute: (k, v) => { attrMap[k] = v; },
    removeAttribute: (k) => { delete attrMap[k]; },
    getAttribute: (k) => (k in attrMap ? attrMap[k] : null),
    querySelectorAll: (sel) => (/^[a-z0-9]+$/i.test(sel) ? childList.filter((k) => k.tag === sel.toLowerCase()) : []),
    _classes: classList,
    _attrs: attrMap,
    _style: styleMap,
  };
}

function blk(extra = {}) {
  return {
    id: 'blk_1', type: 'core.heading', version: 1,
    props: { text: 'Hello' }, style: {}, visibility: {}, bindings: {}, children: [], ...extra,
  };
}

const doc1 = (block) => ({ sections: [{ id: 'sec_1', blocks: [block] }] });

// ── Class and attribute deltas ───────────────────────────────────────────

test('motion type changes become class add/remove', () => {
  const p = patch.computeNodePatch(blk(), blk({ animation: { type: 'fade_up' } }));
  assert.deepEqual(p.classes.add, ['sb-animate-fade-up']);
  assert.deepEqual(p.classes.remove, []);

  const off = patch.computeNodePatch(blk({ animation: { type: 'fade_up' } }), blk({ animation: { type: 'none' } }));
  assert.deepEqual(off.classes.remove, ['sb-animate-fade-up']);
});

test('interaction trigger drives both a class and an attribute', () => {
  const on = patch.computeNodePatch(blk(), blk({ interactions: { trigger: 'viewport-enter' } }));
  assert.deepEqual(on.classes.add, ['sb-interaction-viewport-enter']);
  assert.deepEqual(on.attrs.set, { 'data-sb-interaction-trigger': 'viewport-enter' });

  const off = patch.computeNodePatch(blk({ interactions: { trigger: 'hover' } }), blk({ interactions: {} }));
  assert.deepEqual(off.classes.remove, ['sb-interaction-hover']);
  assert.deepEqual(off.attrs.remove, ['data-sb-interaction-trigger']);
});

test('align is patchable because it is a plain class token', () => {
  const p = patch.computeNodePatch(blk(), blk({ style: { align: { base: 'center' } } }));
  assert.deepEqual(p.classes.add, ['sb-align-base-center']);
});

// ── Motion custom properties ─────────────────────────────────────────────

test('motionVars mirrors the renderer and clamps', () => {
  const vars = patch.motionVars(blk({ animation: { type: 'fade_in', duration_ms: 99999, easing: 'ease-out' } }));
  assert.equal(vars['--sb-anim-duration'], '4000ms');
  assert.equal(vars['--sb-anim-easing'], 'ease-out');

  // An easing the server would refuse must not be smuggled through the canvas.
  assert.equal(patch.motionVars(blk({ animation: { type: 'fade_in', easing: 'evil()' } }))['--sb-anim-easing'], undefined);
  // type 'none' means no animation, so no variables at all.
  assert.deepEqual(patch.motionVars(blk({ animation: { type: 'none', duration_ms: 500 } })), {});
});

test('applying a patch replaces stale motion custom properties', () => {
  const el = fakeEl({ classes: ['sb-animate-fade-up'], style: { '--sb-anim-duration': '900ms', '--sb-anim-delay': '150ms' } });
  const p = { classes: { add: [], remove: ['sb-animate-fade-up'] }, attrs: { set: {}, remove: [] },
    motionVars: { '--sb-anim-duration': '300ms' }, texts: [] };
  assert.equal(patch.applyNodePatch(el, p), true);
  assert.equal(el._classes.has('sb-animate-fade-up'), false);
  // The stale delay must be gone, not left behind still animating.
  assert.equal(el._style['--sb-anim-delay'], undefined);
  assert.equal(el._style['--sb-anim-duration'], '300ms');
});

// ── The self-validation contract ─────────────────────────────────────────

test('text is written only when the element still holds the old value', () => {
  const h2 = fakeEl({ tag: 'h2', text: 'Hello' });
  const el = fakeEl({ kids: [h2] });
  const p = patch.computeNodePatch(blk(), blk({ props: { text: 'Hello world' } }));
  assert.equal(patch.applyNodePatch(el, p), true);
  assert.equal(h2.textContent, 'Hello world');
});

test('a wrapper that reads the same as its only child is NOT ambiguous', () => {
  // The block div and the h2 inside it both read "Hello" in a real browser.
  // Treating that as ambiguous would mean no text edit ever patches — found by
  // running against a real DOM, not by the stub.
  const h2 = fakeEl({ tag: 'h2', text: 'Hello' });
  const wrapper = fakeEl({ text: 'Hello', kids: [h2] });
  const p = patch.computeNodePatch(blk(), blk({ props: { text: 'Goodbye' } }));
  assert.equal(patch.applyNodePatch(wrapper, p), true);
  assert.equal(h2.textContent, 'Goodbye');
});

test('a direct text node on the block itself is patchable', () => {
  const el = fakeEl({ text: 'Hello', kids: [] });
  const p = patch.computeNodePatch(blk(), blk({ props: { text: 'Goodbye' } }));
  assert.equal(patch.applyNodePatch(el, p), true);
  assert.equal(el.textContent, 'Goodbye');
});

test('a stale element REFUSES the text write and asks for a reload', () => {
  // The element no longer holds the old text, so writing would corrupt the
  // wrong element. The whole patch is refused rather than guessed.
  const h2 = fakeEl({ tag: 'h2', text: 'Something else entirely' });
  const el = fakeEl({ kids: [h2] });
  const p = patch.computeNodePatch(blk(), blk({ props: { text: 'Hello world' } }));
  assert.equal(patch.applyNodePatch(el, p), false);
  assert.equal(h2.textContent, 'Something else entirely', 'must not have been modified');
});

test('ambiguous text (two matching elements) is refused', () => {
  // Refusing on ambiguity is the point: guessing would silently corrupt one.
  const a = fakeEl({ tag: 'p', text: 'Hello' });
  const b = fakeEl({ tag: 'p', text: 'Hello' });
  const el = fakeEl({ kids: [a, b] });
  const prev = blk({ type: 'core.paragraph' });
  const next = blk({ type: 'core.paragraph', props: { text: 'Bye' } });
  assert.equal(patch.applyNodePatch(el, patch.computeNodePatch(prev, next)), false);
  assert.equal(a.textContent, 'Hello');
  assert.equal(b.textContent, 'Hello');
});

test('structural and provider-driven changes always decline', () => {
  const base = blk();
  assert.equal(patch.computeNodePatch(base, blk({ children: [{ id: 'blk_2' }] })), null, 'children change');
  assert.equal(patch.computeNodePatch(base, blk({ bindings: { x: { provider: 'p' } } })), null, 'bindings change');
  assert.equal(patch.computeNodePatch(base, blk({ responsive: { mobile: { hide: true } } })), null, 'responsive change');
  assert.equal(patch.computeNodePatch(base, blk({ type: 'core.paragraph' })), null, 'type change');
  // A non-align style key goes through the renderer's token resolution.
  assert.equal(patch.computeNodePatch(base, blk({ style: { surface_token: 'surface.accent' } })), null, 'token style');
  // An unknown prop could render anywhere, so it must not be patched blind.
  assert.equal(patch.computeNodePatch(base, blk({ props: { text: 'Hello', html: '<b>x</b>' } })), null, 'unknown prop');
});

// ── The canvas-level entry point ─────────────────────────────────────────

test('patchCanvas: adding a block reloads (shape is not a class patch)', () => {
  const canvas = { querySelector: () => null };
  const r = patch.patchCanvas(canvas, doc1(blk()), {
    sections: [{ id: 'sec_1', blocks: [blk(), blk({ id: 'blk_2' })] }],
  });
  assert.equal(r.reload, true);
  assert.equal(r.reason, 'section-shape');
});

test('patchCanvas: a swapped node id reloads rather than patching the wrong node', () => {
  // Same block COUNT, so the shape signature matches — this must still be caught
  // rather than reporting "no change" and leaving a stale block on the canvas.
  const canvas = { querySelector: () => null };
  const r = patch.patchCanvas(canvas, doc1(blk()), doc1(blk({ id: 'blk_2' })));
  assert.equal(r.reload, true);
  assert.equal(r.reason, 'node-set');
});

test('patchCanvas: patches a located node and reports it', () => {
  const el = fakeEl();
  const canvas = { querySelector: () => el };
  const r = patch.patchCanvas(canvas, doc1(blk()), doc1(blk({ animation: { type: 'scale_up' } })));
  assert.equal(r.reload, false);
  assert.deepEqual(r.ids, ['blk_1']);
  assert.equal(el._classes.has('sb-animate-scale-up'), true);
});

test('patchCanvas: an identical document is a no-op, not a reload', () => {
  const canvas = { querySelector: () => null };
  const d = doc1(blk());
  const r = patch.patchCanvas(canvas, d, d);
  assert.equal(r.reload, false);
  assert.equal(r.patched, 0);
});

test('indexBlocks: flattens nested children for comparison', () => {
  const idx = patch.indexBlocks({
    sections: [{ id: 'sec_1', blocks: [blk({ children: [blk({ id: 'blk_2' })] })] }],
  });
  assert.deepEqual([...idx.keys()].sort(), ['blk_1', 'blk_2']);
});
test('hiding a device mirrors the renderer hideClasses()', () => {
  const p = patch.computeNodePatch(
    blk({ visibility: { auth_state: 'any', devices: ['base', 'sm', 'md', 'lg'] } }),
    blk({ visibility: { auth_state: 'any', devices: ['base'] } }),
  );
  assert.deepEqual(p.classes.add.sort(), ['sb-hide-lg', 'sb-hide-md', 'sb-hide-sm']);
});