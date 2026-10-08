import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { surfaceAccepts, acceptsSurfaceDraft, SURFACE_PATHS } from '../src/core/styleSurface.mjs';

const fixture = JSON.parse(readFileSync(new URL('./fixtures/style-surface.json', import.meta.url), 'utf8'));

test('every shared fixture case is accepted or refused exactly as the server does', () => {
  for (const { path, value, ok } of fixture.cases) {
    assert.equal(surfaceAccepts(path, value), ok, `${path} = ${JSON.stringify(value)}`);
  }
});

test('an unknown path is never acceptable, and an empty draft means unset', () => {
  assert.equal(surfaceAccepts('layout.nope', '1rem'), false);
  assert.equal(surfaceAccepts('hover.color', '#fff'), false);
  assert.equal(acceptsSurfaceDraft('layout.gap', ''), true);
  assert.equal(acceptsSurfaceDraft('layout.gap', '12'), false);
  assert.ok(SURFACE_PATHS.includes('effects.transition.easing'));
});

import { getPath, setPath, setPaths } from '../src/core/styleSurface.mjs';

test('setPath sets, nests and prunes without mutating', () => {
  const a = { layout: { gap: '1rem' }, color: '#fff' };
  const b = setPath(a, 'layout.direction', 'row');
  assert.deepEqual(b, { layout: { gap: '1rem', direction: 'row' }, color: '#fff' });
  assert.deepEqual(a, { layout: { gap: '1rem' }, color: '#fff' }, 'input untouched');
  assert.deepEqual(setPath(a, 'layout.gap', undefined), { color: '#fff' }, 'the emptied group goes too');
  assert.deepEqual(setPath({}, 'effects.transform.rotate', 10), { effects: { transform: { rotate: 10 } } });
  assert.deepEqual(setPath({ effects: { transform: { rotate: 10 } } }, 'effects.transform.rotate', undefined), {});
  assert.equal(getPath(b, 'layout.direction'), 'row');
  assert.equal(getPath(b, 'layout.nope.deeper'), undefined);
  assert.deepEqual(setPaths({}, [['margin.top', '1rem'], ['margin.left', '1rem']]), { margin: { top: '1rem', left: '1rem' } });
});
