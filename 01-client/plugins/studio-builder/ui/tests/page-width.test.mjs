// CL-01: the page "Width" setting never reached the page (sections choose their own width), so the Page
// inspector no longer offers it, and the Site settings hint says where widths are really chosen.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { t } from '../src/core/messages.mjs';

test('the Page inspector offers no page width', () => {
  const src = readFileSync(new URL('../src/components/inspectors/PageInspector.jsx', import.meta.url), 'utf8');
  assert.doesNotMatch(src, /container_width/);
});

test('the Site settings hint says sections pick their width', () => {
  assert.match(t('ss_hint_layout'), /Each section picks/);
  assert.doesNotMatch(t('ss_hint_layout'), /Page settings/);
});
