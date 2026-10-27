// DT-01: the mobile Theme sheet edits the same tokens as the desktop Theme dialog.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (p) => readFileSync(new URL(p, import.meta.url), 'utf8');

test('the mobile Theme sheet renders the Site settings form, like the desktop Theme dialog', () => {
  const sheet = read('../src/components/ThemeBottomSheet.jsx');
  assert.match(sheet, /import \{ SiteSettingsForm \} from '\.\/SiteSettings\.jsx'/);
  assert.match(sheet, /<SiteSettingsForm onSaved=\{onSaved\} \/>/);
  const dialog = read('../src/components/ThemeDialog.jsx');
  assert.match(dialog, /<SiteSettingsForm onSaved=\{onSaved\} actionsTarget=\{actionsSlot\} \/>/);
});

test('the Theme dialog shows Save design in its footer beside Close, not in a bar over the fields', () => {
  const dialog = read('../src/components/ThemeDialog.jsx');
  assert.match(dialog, /onClick=\{onClose\}>\{t\('close'\)\}<\/button>\s*<div className="sbx-ss__foot" ref=\{setActionsSlot\} \/>/, 'the slot follows Close in the footer');
  const form = read('../src/components/SiteSettings.jsx');
  assert.match(form, /actionsTarget \? createPortal\(actions, actionsTarget\)/, 'the form puts its actions there');
  assert.match(read('../src/builder.css'), /\.sbx-ss__bar \{[^}]*background: var\(--sbx-panel\)/, 'elsewhere the bar matches the panel it sits on');
});

test('the mobile Theme sheet submits no token ref of its own (the server refused them as unknown_token)', () => {
  const sheet = read('../src/components/ThemeBottomSheet.jsx');
  assert.doesNotMatch(sheet, /['"]color\.[a-z]+['"]/, 'no hard-coded colour refs');
  assert.doesNotMatch(sheet, /transport\.(saveTokens|tokens)\(/, 'saving goes through the shared form');
  assert.doesNotMatch(sheet, /alert\(/, 'no placeholder actions');
});
