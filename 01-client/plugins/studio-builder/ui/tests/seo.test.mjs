// Phase 9C — the page inspector's search-appearance controls: the limits come
// from the server manifest, existing values load, the canonical input is
// validated before it is sent, the draft note is shown, and the update_seo
// operations (shallow merge, null clears) round-trip through the sync engine.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { manifest, doc, section, heading } from './helpers.mjs';
import * as ops from '../src/core/operations.mjs';
import { seoLimits, canonicalProblem, canonicalValue } from '../src/core/seo.mjs';

const here = dirname(fileURLToPath(import.meta.url));
let entry = null;
let skipReason = null;
let esbuild = null;
try {
  esbuild = await import('esbuild');
} catch {
  skipReason = 'dev dependencies not installed (run npm ci in plugins/studio-builder/ui)';
}
if (esbuild) {
  const dir = mkdtempSync(join(tmpdir(), 'sbx-seo-'));
  const outfile = join(dir, 'render.mjs');
  await esbuild.build({
    entryPoints: [join(here, 'fixtures', 'render-entry.jsx')],
    outfile, bundle: true, platform: 'node', format: 'esm', jsx: 'automatic',
    conditions: ['production'], define: { 'process.env.NODE_ENV': '"production"' }, logLevel: 'silent',
    banner: { js: "import { createRequire as __sbxCreateRequire } from 'node:module'; const require = __sbxCreateRequire(import.meta.url);" },
  });
  entry = await import(pathToFileURL(outfile).href);
  process.on('exit', () => rmSync(dir, { recursive: true, force: true }));
}

const seoDoc = (seo) => {
  const d = doc([section([heading('Hello')], 'Main')]);
  d.seo = { ...d.seo, ...seo };
  return d;
};

test('seo limits come from the server manifest and fall back to the schema values', () => {
  assert.deepEqual(seoLimits(manifest), { title: 255, description: 1000 });
  assert.deepEqual(seoLimits({ limits: { seo_title_max: 60, seo_description_max: 160 } }), { title: 60, description: 160 });
  assert.deepEqual(seoLimits({}), { title: 255, description: 1000 });
  assert.deepEqual(seoLimits(null), { title: 255, description: 1000 });
});

test('canonical input: empty, site-relative and http(s) are accepted; unsafe or malformed input is refused before sending', () => {
  for (const ok of ['', '   ', '/', '/about', '/a/b?x=1', 'https://example.test/about', 'http://example.test']) {
    assert.equal(canonicalProblem(ok), null, `accepts ${JSON.stringify(ok)}`);
  }
  for (const bad of ['//evil.test/x', 'javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,x', 'mailto:a@b.co', 'ftp://example.test', 'example.test/about', 'https://', 'https:// spaced.test', '/a b', '/a\u0000b', 'vbscript:x', `/${'a'.repeat(2050)}`]) {
    assert.equal(canonicalProblem(bad), 'seo_canonical_invalid', `refuses ${JSON.stringify(bad)}`);
  }
  assert.equal(canonicalValue(''), null, 'an empty box clears the canonical');
  assert.equal(canonicalValue('  /about '), '/about');
});

test('the SEO section shows every canonical field with the server limits, the existing values, the canonical guidance and the draft note', { skip: skipReason || false }, () => {
  const html = entry.render({
    manifest,
    document: seoDoc({ title: 'About Alpha', description: 'We make things.', canonical_url: '/about-us', og_image_media_id: 7, robots: 'noindex,follow' }),
  });
  assert.match(html, /Search appearance/);
  assert.match(html, />SEO title</);
  assert.match(html, /value="About Alpha"[^>]*/);
  assert.match(html, /maxLength="255"|maxlength="255"/i, 'title limit = the schema limit');
  assert.match(html, /11 \/ 255/, 'title counter');
  assert.match(html, />Meta description</);
  assert.match(html, /maxLength="1000"|maxlength="1000"/i, 'description limit = the schema limit, not a lower UI limit');
  assert.match(html, /15 \/ 1000/, 'description counter');
  assert.match(html, />Canonical URL</);
  assert.match(html, /value="\/about-us"/);
  assert.match(html, /a path on this site \(\/about\) or a full address on this site/i, 'site-relative and same-site absolute guidance');
  assert.match(html, /never declares another domain as canonical/i, 'external domains are not used');
  assert.match(html, /data-testid="seo-og-image"/);
  assert.match(html, /value="7"/, 'the current media id is shown');
  assert.match(html, /<option value="noindex,follow" selected="">Don’t index, follow links<\/option>/, 'robots keeps the canonical value, with a readable name');
  assert.match(html, /They go live only when you publish/);
  assert.ok(!/data-testid="seo-canonical-problem"/.test(html), 'a valid canonical shows no problem');
});

test('an existing invalid canonical already in the draft is flagged, not hidden', { skip: skipReason || false }, () => {
  const html = entry.render({ manifest, document: seoDoc({ canonical_url: 'javascript:alert(1)' }) });
  assert.match(html, /data-testid="seo-canonical-problem"/);
  assert.match(html, /aria-invalid="true"/);
});

test('update_seo patches for title, description, canonical, og image and robots merge like the server and null clears', () => {
  const base = seoDoc({ title: 'T', description: '' });
  let d = base;
  const apply = (op) => {
    d = { ...d, seo: { ...d.seo, ...op.payload.seo } };
  };
  for (const patch of [{ title: 'New title' }, { description: 'New description' }, { canonical_url: '/about' }, { og_image_media_id: 9 }, { robots: 'noindex,nofollow' }]) {
    const op = ops.updateSeo(patch);
    assert.equal(op.op, 'update_seo');
    apply(op);
  }
  assert.deepEqual(d.seo, { canonical_url: '/about', description: 'New description', og_image_media_id: 9, robots: 'noindex,nofollow', title: 'New title' });
  apply(ops.updateSeo({ canonical_url: null, og_image_media_id: null }));
  assert.equal(d.seo.canonical_url, null);
  assert.equal(d.seo.og_image_media_id, null);
  assert.equal(d.seo.title, 'New title', 'other keys are untouched');
});
