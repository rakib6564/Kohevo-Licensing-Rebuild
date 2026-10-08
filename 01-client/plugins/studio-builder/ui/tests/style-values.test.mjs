import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { CHECKS, acceptsDraft, isToken } from '../src/core/styleValues.mjs';

const fixture = JSON.parse(readFileSync(new URL('./fixtures/style-values.json', import.meta.url), 'utf8'));

test('every shared fixture case agrees with the PHP guard (same file is read by the PHP unit test)', () => {
  assert.ok(fixture.cases.length > 80);
  for (const { kind, value, ok } of fixture.cases) {
    assert.ok(CHECKS[kind], `unknown kind ${kind}`);
    assert.equal(CHECKS[kind](value), ok, `${kind} ${JSON.stringify(value)} should be ${ok ? 'accepted' : 'refused'}`);
  }
});

test('an empty draft means inherit and is always accepted', () => {
  for (const kind of Object.keys(CHECKS)) {
    assert.equal(acceptsDraft(kind, ''), true);
    assert.equal(acceptsDraft(kind, undefined), true);
  }
  assert.equal(acceptsDraft('length', 'url(x)'), false);
  assert.equal(acceptsDraft('nonsense', 'x'), false);
});

test('token references are recognised', () => {
  assert.equal(isToken('space.4'), true);
  assert.equal(isToken('space'), false);
  assert.equal(isToken('Space.4'), false);
});
