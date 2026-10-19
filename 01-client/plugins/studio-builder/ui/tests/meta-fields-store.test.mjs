import test from 'node:test';
import assert from 'node:assert/strict';
import { setMetaFields, getMetaFields, metaFieldGroups, isKnownMetaField } from '../src/core/metaFields.mjs';
import { controlFor } from '../src/core/fields.mjs';

test('a string field with the meta_field format gets the field dropdown', () => {
  assert.equal(controlFor({ type: 'string', format: 'meta_field' }), 'metafield');
  assert.equal(controlFor({ type: 'string' }), 'text');
});

test('the store keeps well-formed fields only, and falls back to the key for a missing label', () => {
  setMetaFields([
    { key: 'client', label: 'Client', type: 'text', postType: 'portfolio', postTypeLabel: 'Portfolio Item' },
    { key: 'Bad Key', label: 'x' },
    { key: 'year', postType: 'portfolio' },
    null,
    { key: 5 },
  ]);
  assert.deepEqual(getMetaFields().map((f) => [f.key, f.label]), [['client', 'Client'], ['year', 'year']]);
  setMetaFields('nope');
  assert.deepEqual(getMetaFields(), []);
});

test('fields are grouped by content type in the order the types first appear, and known keys are found', () => {
  setMetaFields([
    { key: 'a', postType: 'services', postTypeLabel: 'Service' },
    { key: 'b', postType: 'portfolio', postTypeLabel: 'Portfolio Item' },
    { key: 'c', postType: 'services', postTypeLabel: 'Service' },
  ]);
  const groups = metaFieldGroups();
  assert.deepEqual(groups.map((g) => [g.label, g.fields.map((f) => f.key)]), [['Service', ['a', 'c']], ['Portfolio Item', ['b']]]);
  assert.equal(isKnownMetaField('b'), true);
  assert.equal(isKnownMetaField('zzz'), false);
  setMetaFields([]);
});
