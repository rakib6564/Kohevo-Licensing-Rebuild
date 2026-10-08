import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { PROP_PRESENTERS, presentersFor } from '../src/core/propPresenters.mjs';

const manifest = JSON.parse(readFileSync(new URL('./fixtures/manifest.json', import.meta.url), 'utf8'));

function fieldSchemas() {
  const out = new Map();
  (function walk(o) {
    if (Array.isArray(o)) o.forEach(walk);
    else if (o && typeof o === 'object') {
      if (typeof o.type === 'string' && Array.isArray(o.field_schema)) out.set(o.type, o.field_schema);
      Object.values(o).forEach(walk);
    }
  })(manifest);
  return out;
}

test('every presenter names a real field of its block, with a matching kind', () => {
  const schemas = fieldSchemas();
  for (const [type, map] of Object.entries(PROP_PRESENTERS)) {
    const fields = schemas.get(type);
    assert.ok(fields, `${type} is in the manifest`);
    for (const [key, p] of Object.entries(map)) {
      const f = fields.find((x) => x.key === key);
      assert.ok(f, `${type}.${key} exists`);
      if (p.tiles) assert.equal(f.type, 'number');
      else assert.equal(f.type, 'enum');
    }
  }
});

test('an icon presenter has an icon for each allowed value', () => {
  const source = readFileSync(new URL('../src/components/inspectors/InspectorIcons.jsx', import.meta.url), 'utf8');
  const schemas = fieldSchemas();
  for (const [type, map] of Object.entries(PROP_PRESENTERS)) {
    for (const [key, p] of Object.entries(map)) {
      if (!p.icons) continue;
      const f = schemas.get(type).find((x) => x.key === key);
      for (const v of f.allowed_values) {
        const name = `${p.icons}_${String(p.map?.[v] ?? v).replace(/-/g, '_')}`;
        assert.ok(new RegExp(`\\b${name}:`).test(source), `${type}.${key}=${v} -> ${name}`);
      }
    }
  }
});

test('presentersFor returns null for blocks that keep plain selects', () => {
  assert.equal(presentersFor('core.heading'), null);
  assert.ok(presentersFor('layout.flex').direction);
});
