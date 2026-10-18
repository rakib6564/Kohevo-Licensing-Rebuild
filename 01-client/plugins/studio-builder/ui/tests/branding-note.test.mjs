// DT-02: only the accent colour from Settings › Branding reaches Studio, and the Site settings note says so.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { t } from '../src/core/messages.mjs';

test('the branding note promises only the accent colour', () => {
  assert.match(t('theme_branding_note'), /Only the accent colour/);
  assert.doesNotMatch(t('theme_branding_note'), /colours and fonts from/);
});
