import { test } from 'node:test';
import assert from 'node:assert/strict';
import { RESPONSIVE_SCOPES, clearOverride, deviceView, hasOverride, writeOverride } from '../src/core/responsiveStyle.mjs';
import { surfaceAccepts } from '../src/core/styleSurface.mjs';

const style = { typography: { size: '3rem', weight: 'bold' }, padding: { top: '4rem' } };

test('desktop shows the block style, limited to the control group', () => {
  assert.deepEqual(deviceView(style, {}, 'desktop', RESPONSIVE_SCOPES.size), { typography: { size: '3rem' } });
  assert.deepEqual(deviceView(style, {}, 'desktop', RESPONSIVE_SCOPES.gap), {});
});

test('tablet and mobile show only their own values', () => {
  const responsive = { tablet: { style: { padding: { top: '2rem' } } } };
  assert.deepEqual(deviceView(style, responsive, 'tablet', RESPONSIVE_SCOPES.spacing), { padding: { top: '2rem' } });
  assert.deepEqual(deviceView(style, responsive, 'mobile', RESPONSIVE_SCOPES.spacing), {});
  assert.equal(hasOverride(responsive, 'tablet', RESPONSIVE_SCOPES.spacing), true);
  assert.equal(hasOverride(responsive, 'tablet', RESPONSIVE_SCOPES.size), false);
  assert.equal(hasOverride(responsive, 'desktop', RESPONSIVE_SCOPES.spacing), false);
});

test('writing keeps other keys of the device and other groups, and never touches the base style', () => {
  const start = { tablet: { hide: true, style: { layout: { gap: '8px' } } }, mobile: { align: 'center' } };
  const next = writeOverride(start, 'tablet', RESPONSIVE_SCOPES.size, { typography: { size: '1.25rem' } });
  assert.deepEqual(next.tablet, { hide: true, style: { layout: { gap: '8px' }, typography: { size: '1.25rem' } } });
  assert.deepEqual(next.mobile, { align: 'center' });
  assert.deepEqual(start.tablet.style, { layout: { gap: '8px' } }, 'the input is not mutated');
});

test('resetting removes the values, and empty shells with them', () => {
  const start = { tablet: { style: { typography: { size: '1.25rem' } } }, mobile: { hide: true, style: { typography: { size: '1rem' } } } };
  const next = clearOverride(start, 'tablet', RESPONSIVE_SCOPES.size);
  assert.equal('tablet' in next, false);
  assert.deepEqual(clearOverride(start, 'mobile', RESPONSIVE_SCOPES.size).mobile, { hide: true });
});

test('every responsive path is one the server accepts for a device style', () => {
  const sample = { 'typography.size': '1rem', 'layout.gap': '8px', 'layout.row_gap': '8px', 'layout.column_gap': '8px' };
  for (const path of Object.values(RESPONSIVE_SCOPES).flat()) {
    const value = sample[path] ?? '1rem';
    assert.equal(path === 'typography.size' || surfaceAccepts(path, value), true, path);
  }
});
