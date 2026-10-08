import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { applyLocal } from '../src/core/operations.mjs';
import { lockViolation } from '../src/core/layerLock.mjs';

const fixture = JSON.parse(readFileSync(new URL('./fixtures/operations-p3b.json', import.meta.url), 'utf8'));

/** Key-sorted deep copy, so key order never decides equality. */
const canon = (v) => {
  if (Array.isArray(v)) return v.map(canon);
  if (v && typeof v === 'object') return Object.fromEntries(Object.keys(v).sort().map((k) => [k, canon(v[k])]));
  return v;
};

function setPath(tree, path, value) {
  const [head, ...rest] = path;
  const copy = Array.isArray(tree) ? [...tree] : { ...tree };
  copy[head] = rest.length ? setPath(tree[head], rest, value) : value;
  return copy;
}
function unsetAt(tree, path) {
  const [head, ...rest] = path;
  const copy = Array.isArray(tree) ? [...tree] : { ...tree };
  if (rest.length) copy[head] = unsetAt(tree[head], rest);
  else delete copy[head];
  return copy;
}

test('every shared operation case produces the document the PHP applier produces (same fixture)', () => {
  assert.ok(fixture.cases.length >= 25);
  for (const c of fixture.cases) {
    const operation = { op: c.op, payload: c.payload };
    if (c.error) {
      assert.throws(() => applyLocal(fixture.document, operation), undefined, `${c.name} should be refused`);
      continue;
    }
    let expected = fixture.document;
    for (const s of c.set) expected = setPath(expected, s.path, s.value);
    for (const u of c.unset) expected = unsetAt(expected, u);
    assert.deepEqual(canon(applyLocal(fixture.document, operation)), canon(expected), c.name);
  }
});

test('the operations never mutate the document they are given', () => {
  const before = JSON.stringify(fixture.document);
  for (const c of fixture.cases) {
    try { applyLocal(fixture.document, { op: c.op, payload: c.payload }); } catch { /* refusals are covered above */ }
  }
  assert.equal(JSON.stringify(fixture.document), before);
});

test('the layer lock refuses the new operations on a locked node, and only that node', () => {
  const locked = JSON.parse(JSON.stringify(fixture.document));
  locked.sections[0].blocks[0].metadata = { locked: true };
  const blockOps = fixture.cases.filter((c) => c.payload.block_id === fixture.document.sections[0].blocks[0].id && !c.error);
  assert.ok(blockOps.length > 0);
  for (const c of blockOps) assert.equal(lockViolation(locked, { op: c.op, payload: c.payload }), 'locked', `${c.name} on a locked block`);
  const sectionLocked = JSON.parse(JSON.stringify(fixture.document));
  sectionLocked.sections[1].locked = true;
  assert.equal(lockViolation(sectionLocked, { op: 'update_section_style', payload: { section_id: sectionLocked.sections[1].id, style: {} } }), 'locked');
  assert.equal(lockViolation(sectionLocked, { op: 'update_section_style', payload: { section_id: sectionLocked.sections[0].id, style: {} } }), null);
});
