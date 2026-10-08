// i18n parity for the builder UI: the JS message table (EN), the PHP boot list
// (admin/builder.php `messages`, which carries the translations to the browser) and the
// French pack (lang/fr.php) must agree. CI's check-i18n only sees PHP __() calls, so a key
// used by the UI but missing from the boot list silently shows English to French users.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { t } from '../src/core/messages.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const plugin = join(here, '..', '..');
const php = readFileSync(join(plugin, 'admin', 'builder.php'), 'utf8');
const fr = readFileSync(join(plugin, 'lang', 'fr.php'), 'utf8');
const unescapePhp = (s) => s.replace(/\\(['\\])/g, '$1');

/** boot.messages entries: { jsKey → default text } */
const boot = new Map();
for (const m of php.matchAll(/'([a-z0-9_]+)'\s*=>\s*__\('studio_ui_([a-z0-9_]+)',\s*'((?:[^'\\]|\\.)*)'\)/g)) {
  assert.equal(m[1], m[2], `boot key '${m[1]}' must map to studio_ui_${m[1]}`);
  boot.set(m[1], unescapePhp(m[3]));
}
const frKeys = new Set([...fr.matchAll(/'studio_ui_([a-z0-9_]+)'\s*=>/g)].map((m) => m[1]));

// Components touched by Builder 2.0 phase 1: every user-visible string goes through t().
const TOUCHED = [
  'StudioShell', 'LeftPanel', 'TopBar', 'CanvasArea', 'CanvasOverlay', 'BottomBar', 'MobileDock', 'Outline',
  'BlockPalette', 'MoreBottomSheet', 'ThemeBottomSheet', 'ConflictBanner', 'VisitorPreview', 'RightPanel',
  'HistoryDialog', 'InsertDialog', 'sheets/ResponsiveViewSheet', 'sheets/SheetPrimitive',
];
const usedKeys = new Map(); // key → first file
for (const name of TOUCHED) {
  const file = join(here, '..', 'src', 'components', `${name}.jsx`);
  if (!existsSync(file)) continue;
  const src = readFileSync(file, 'utf8');
  for (const m of src.matchAll(/\bt\('([a-z0-9_]+)'/g)) if (!usedKeys.has(m[1])) usedKeys.set(m[1], name);
  for (const m of src.matchAll(/\bt\(`([a-z0-9_]+_)\$\{/g)) usedKeys.set(`${m[1]}*`, name); // dynamic family
}

test('every boot message has a French translation', () => {
  const missing = [...boot.keys()].filter((k) => !frKeys.has(k));
  assert.deepEqual(missing, []);
});

test('every boot message default matches the JS English text', () => {
  const drift = [];
  for (const [k, text] of boot) if (t(k) !== text && t(k) !== k) drift.push(k);
  // Pre-existing drift is tolerated only for keys older than this phase; none is allowed for touched components.
  const touchedDrift = drift.filter((k) => usedKeys.has(k));
  assert.deepEqual(touchedDrift, []);
});

test('every key used by the touched components exists in English and ships in boot.messages with French', () => {
  const problems = [];
  for (const [key, file] of usedKeys) {
    if (key.endsWith('*')) continue;
    if (t(key) === key) problems.push(`${file}: t('${key}') has no English text`);
    else if (!boot.has(key)) problems.push(`${file}: '${key}' is not in boot.messages (French users would see English)`);
    else if (!frKeys.has(key)) problems.push(`${file}: '${key}' has no French entry`);
  }
  assert.deepEqual(problems, []);
});

test('dynamic key families used by the touched components are complete (EN + boot + FR)', () => {
  const families = [...usedKeys.keys()].filter((k) => k.endsWith('*')).map((k) => k.slice(0, -1));
  const problems = [];
  for (const prefix of families) {
    const keys = [...boot.keys()].filter((k) => k.startsWith(prefix));
    if (keys.length === 0) problems.push(`no boot messages for the '${prefix}*' family`);
    for (const k of keys) if (!frKeys.has(k)) problems.push(`'${k}' has no French entry`);
  }
  assert.deepEqual(problems, []);
});

test('no hard-coded English labels slipped into the touched components', () => {
  // Attribute strings and plain text nodes that look like prose must go through t(). Product names,
  // key identifiers and font names are not translated.
  const allowed = new Set(['KOHEVO STUDIO', 'BUILDER 2.0', 'KOHEVO STUDIO BUILDER 2.0']);
  const offenders = [];
  for (const name of TOUCHED) {
    const file = join(here, '..', 'src', 'components', `${name}.jsx`);
    if (!existsSync(file)) continue;
    const lines = readFileSync(file, 'utf8').split('\n');
    lines.forEach((line, i) => {
      if (/^\s*(\/\/|\*|\/\*|import )/.test(line)) return;
      for (const m of line.matchAll(/\b(?:title|aria-label|placeholder|alt)="([^"{}]*[A-Za-z]{3,}[^"{}]*)"/g)) {
        if (!allowed.has(m[1])) offenders.push(`${name}:${i + 1}: ${m[0]}`);
      }
    });
  }
  assert.deepEqual(offenders, []);
});
