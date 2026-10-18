// DT-01: the mobile Theme sheet edits the same tokens as the desktop Theme dialog.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (p) => readFileSync(new URL(p, import.meta.url), 'utf8');

test('the mobile Theme sheet renders the Site settings form, like the desktop Theme dialog', () => {
  const sheet = read('../src/components/ThemeBottomSheet.jsx');
  assert.match(sheet, /import \{ SiteSettingsForm \} from '\.\/SiteSettings\.jsx'/);
  assert.match(sheet, /<SiteSettingsForm onSaved=\{onSaved\} \/>/);
  assert.match(read('../src/components/ThemeDialog.jsx'), /<SiteSettingsForm onSaved=\{onSaved\} \/>/);
});

test('the mobile Theme sheet submits no token ref of its own (the server refused them as unknown_token)', () => {
  const sheet = read('../src/components/ThemeBottomSheet.jsx');
  assert.doesNotMatch(sheet, /['"]color\.[a-z]+['"]/, 'no hard-coded colour refs');
  assert.doesNotMatch(sheet, /transport\.(saveTokens|tokens)\(/, 'saving goes through the shared form');
  assert.doesNotMatch(sheet, /alert\(/, 'no placeholder actions');
});
