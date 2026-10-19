import test from 'node:test';
import assert from 'node:assert/strict';
import { conditionsKey, fromRows, rowProblem, rulesOf, sameConditionsAs, setProblem, toRows } from '../src/core/conditions.mjs';

test('stored rules, in the shapes the admin screen and the resolver write, become editable rows', () => {
  assert.deepEqual(toRows({ rules: [{ type: 'include', condition: 'entire_site' }, { action: 'exclude', condition: 'category', value: 'News' }] }).map((r) => [r.action, r.kind, r.value]),
    [['include', 'entire_site', ''], ['exclude', 'category', 'News']]);
  assert.deepEqual(toRows([{ type: 'include', condition: 'singular', value: 'post' }]).map((r) => [r.kind, r.value]), [['singular', 'post']]);
  assert.deepEqual(toRows([{ scope: 'category', value: 'Tech' }]).map((r) => [r.action, r.kind, r.value]), [['include', 'category', 'Tech']]);
  assert.deepEqual(toRows([{ type: 'include', condition: 'front_page' }, { type: 'include', condition: 'slug', value: 'home' }]).map((r) => r.kind), ['front_page', 'slug']);
  assert.deepEqual(toRows(null), []);
  assert.deepEqual(rulesOf({ condition: 'front_page' }).length, 1);
});

test('rows save back as the rules the matcher reads', () => {
  const rows = [
    { action: 'include', kind: 'singular', value: 'post' },
    { action: 'exclude', kind: 'category', value: ' News ' },
    { action: 'include', kind: 'author', value: '7' },
    { action: 'include', kind: 'archive', value: '' },
    { action: 'include', kind: 'archive', value: 'tutorials' },
    { action: 'include', kind: '404', value: '' },
  ];
  assert.deepEqual(fromRows(rows), { rules: [
    { action: 'include', condition: 'singular', value: 'post' },
    { action: 'exclude', condition: 'category', value: 'News' },
    { action: 'include', condition: 'author', value: 7 },
    { action: 'include', condition: 'archive', value: 'all' },
    { action: 'include', condition: 'archive', term: 'tutorials' },
    { action: 'include', condition: '404' },
  ] });
});

test('a rule the dialog cannot express is kept and saved back unchanged', () => {
  const odd = { type: 'include', condition: 'singular', field: 'category', field_value: 'Tech' };
  const rows = toRows([odd]);
  assert.equal(rows[0].kind, 'custom');
  assert.deepEqual(fromRows(rows), { rules: [odd] });
});

test('problems: empty text, a bad id, only exclusions, too many rows', () => {
  assert.equal(rowProblem({ action: 'include', kind: 'category', value: '  ' }), 'cond_problem_value');
  assert.equal(rowProblem({ action: 'include', kind: 'author', value: 'x' }), 'cond_problem_number');
  assert.equal(rowProblem({ action: 'include', kind: 'entire_site', value: '' }), null);
  assert.equal(setProblem([{ action: 'exclude', kind: 'entire_site', value: '' }]), 'cond_needs_include');
  assert.equal(setProblem([{ action: 'include', kind: 'entire_site', value: '' }, { action: 'exclude', kind: 'tag', value: 'x' }]), null);
  assert.equal(setProblem(Array.from({ length: 31 }, () => ({ action: 'include', kind: 'entire_site', value: '' }))), 'cond_problem_many');
  assert.equal(setProblem([]), null);
});

test('a misspelled action is kept as a custom rule, never shown as an include (TB-12)', () => {
  const rows = toRows([{ action: 'excludes', condition: 'category', value: 'News' }, { type: 'exlcude', condition: 'tag', value: 'x' }]);
  assert.deepEqual(rows.map((r) => r.kind), ['custom', 'custom']);
  assert.deepEqual(toRows([{ type: 'category', value: 'Tech' }]).map((r) => [r.action, r.kind, r.value]), [['include', 'category', 'Tech']], 'a scope named by type is still an include');
  assert.deepEqual(toRows([{ action: 'EXCLUDE', condition: 'category', value: 'News' }]).map((r) => [r.action, r.kind]), [['exclude', 'category']]);
});

test('templates with the same conditions are named, whatever the order, case or spelling of the rules (TB-10)', () => {
  const peers = [
    { id: 2, title: 'Zulu header', conditions: { rules: [{ type: 'include', condition: 'entire_site' }, { action: 'exclude', condition: 'category', value: 'news' }] } },
    { id: 3, title: 'Blog header', conditions: { rules: [{ action: 'include', condition: 'category', value: 'Blog' }] } },
    { id: 4, title: 'Unconditioned', conditions: null },
  ];
  const mine = { rules: [{ action: 'exclude', condition: 'category', value: 'News' }, { action: 'include', condition: 'all' }] };
  assert.deepEqual(sameConditionsAs(mine, peers).map((p) => p.id), [2]);
  assert.deepEqual(sameConditionsAs({ rules: [{ action: 'include', condition: 'category', value: 'blog ' }] }, peers).map((p) => p.id), [3]);
  assert.deepEqual(sameConditionsAs(null, peers), [], 'no conditions: no tie is named');
  assert.deepEqual(sameConditionsAs({ rules: [{ action: 'include', condition: 'front_page' }] }, peers), []);
  assert.equal(conditionsKey({ rules: [] }), '');
});
