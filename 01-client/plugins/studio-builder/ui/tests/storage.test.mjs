// The browser is never a document store: no Web Storage / IndexedDB / cookies
// anywhere in the builder source or in the shipped bundle.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const FORBIDDEN = /\b(localStorage|sessionStorage|indexedDB|IDBFactory|document\.cookie|caches\.open|openDatabase)\b/;

function files(dir, exts) {
  const out = [];
  for (const f of readdirSync(dir)) {
    const p = join(dir, f);
    if (statSync(p).isDirectory()) out.push(...files(p, exts));
    else if (exts.some((e) => p.endsWith(e))) out.push(p);
  }
  return out;
}

const stripComments = (src) => src.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/.*$/gm, '$1');

test('builder source never touches browser storage', () => {
  const sources = files(join(here, '..', 'src'), ['.js', '.mjs', '.jsx']);
  assert.ok(sources.length > 10);
  for (const f of sources) {
    assert.ok(!FORBIDDEN.test(stripComments(readFileSync(f, 'utf8'))), `${f} must not use browser storage`);
  }
});

test('shipped bundle never touches browser storage', () => {
  const dist = join(here, '..', '..', 'assets', 'builder');
  assert.ok(existsSync(join(dist, 'builder.js')), 'prebuilt bundle is present');
  for (const f of files(dist, ['.js'])) {
    assert.ok(!FORBIDDEN.test(readFileSync(f, 'utf8')), `${f} must not use browser storage`);
  }
});
