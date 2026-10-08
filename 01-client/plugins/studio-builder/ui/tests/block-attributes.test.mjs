import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { attributeIssue, classTokenOk, groupAttributes, idFormatOk, idOwner, withAttribute } from '../src/core/blockAttributes.mjs';

const fixture = JSON.parse(readFileSync(new URL('./fixtures/block-attributes.json', import.meta.url), 'utf8'));

test('every shared fixture case is accepted or refused exactly as the server does', () => {
  for (const { name, value, ok } of fixture.cases) {
    assert.equal(attributeIssue(name, value) === null, ok, `${name} = ${JSON.stringify(value)}`);
  }
});

test('ids and class tokens: only what the page will keep', () => {
  for (const id of ['hero', 'a1', 'Pricing-table', 'x_y']) assert.equal(idFormatOk(id), true, id);
  for (const id of ['', '1a', 'a b', 'a.b', '-a', 'a"b', 'é', 'a'.repeat(65)]) assert.equal(idFormatOk(id), false, id);
  for (const c of ['a', 'my-class', 'Cls_2', '9x']) assert.equal(classTokenOk(c), true, c);
  for (const c of ['', 'a.b', 'a:b', 'a/b', '"x', 'é']) assert.equal(classTokenOk(c), false, c);
});

test('idOwner finds a duplicate at any depth and ignores the block being edited', () => {
  const doc = { sections: [{ id: 's', blocks: [
    { id: 'a', attributes: { id: 'one' }, children: [{ id: 'c', attributes: { id: 'deep' } }] },
    { id: 'b', attributes: { id: 'two' } },
  ] }] };
  assert.equal(idOwner(doc, 'deep', 'b'), 'c');
  assert.equal(idOwner(doc, 'one', 'a'), null, 'its own id is not a duplicate');
  assert.equal(idOwner(doc, 'one', 'b'), 'a');
  assert.equal(idOwner(doc, 'free', 'b'), null);
});

test('groupAttributes separates the dedicated fields, data-* rows and everything else', () => {
  const g = groupAttributes({ id: 'x', role: 'region', 'aria-label': 'Pricing', 'data-a': '1', title: 'T', tabindex: 0, 'aria-hidden': 'true' });
  assert.deepEqual(g, { id: 'x', role: 'region', ariaLabel: 'Pricing', data: [['data-a', '1']], other: [['title', 'T'], ['tabindex', '0'], ['aria-hidden', 'true']] });
  assert.deepEqual(groupAttributes(undefined), { id: '', role: '', ariaLabel: '', data: [], other: [] });
});

test('withAttribute sets and removes without mutating', () => {
  const a = { id: 'x', 'data-a': '1' };
  assert.deepEqual(withAttribute(a, 'role', 'main'), { id: 'x', 'data-a': '1', role: 'main' });
  assert.deepEqual(withAttribute(a, 'id', ''), { 'data-a': '1' });
  assert.deepEqual(a, { id: 'x', 'data-a': '1' });
});
