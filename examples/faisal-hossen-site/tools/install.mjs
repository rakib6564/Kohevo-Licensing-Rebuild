// Installs the Faisal Hossen site into a Kohevo Studio tenant through the builder's own authoring API:
// design tokens, custom CSS, the fonts <link>, a shared header and footer, and the seven pages.
//
//   node tools/install.mjs                                   → local sandbox (http://localhost:8200)
//   FH_BASE=https://example.com/site node tools/install.mjs  → a real site: a browser opens, YOU sign in
//   FH_ASSETS=/path/to/public/assets                         → the reference images (default: ../../../Faisal-Hossen-Portfolio/public/assets)
//   FH_MEDIA='{"workbench":1,"profile":2,...}'               → reuse images already uploaded (skips the upload)
//   node tools/install.mjs home work                         → only those slugs
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { session } from './session.mjs';
import { uploadImages } from './media.mjs';
import { applySiteSetup } from './setup.mjs';
import { MEDIA, site } from '../src/content.mjs';
import * as P from '../src/pages.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '..');
const base = process.env.FH_BASE || 'http://localhost:8200';
const only = process.argv.slice(2);
const PAGES = [
  { slug: 'default', title: 'Header', page_type: 'header_partial', route_mode: 'standalone', doc: P.headerDoc },
  { slug: 'default', title: 'Footer', page_type: 'footer_partial', route_mode: 'standalone', doc: P.footerDoc },
  { slug: 'home', title: `${site.name} — Digital Problem Solver`, page_type: 'page', route_mode: 'homepage', doc: P.homeDoc, seo: { description: site.supporting } },
  ...P.EXTRA_PAGES,
];

const { browser, page, api, local } = await session(base);
const must = (r, what) => { if (r.s >= 300 || !r.j?.ok) throw new Error(`${what} failed: HTTP ${r.s} ${JSON.stringify(r.j).slice(0, 600)}`); return r.j.data; };
try {
  // Images → media ids the documents refer to.
  if (process.env.FH_MEDIA) Object.assign(MEDIA, JSON.parse(process.env.FH_MEDIA));
  else {
    const dir = process.env.FH_ASSETS || path.resolve(root, '../../../Faisal-Hossen-Portfolio/public/assets');
    if (!fs.existsSync(dir)) throw new Error(`Image folder not found: ${dir}. Set FH_ASSETS (the reference repo's public/assets) or FH_MEDIA.`);
    Object.assign(MEDIA, await uploadImages(page, dir));
  }
  console.log('media', JSON.stringify(MEDIA));
  await page.goto('plugins/studio-builder/admin/pages.php');

  await applySiteSetup(page, api, must);

  const existing = must(await api('GET', 'pages', null, ''), 'pages').pages || [];
  for (const spec of PAGES) {
    if (only.length && !only.includes(spec.slug)) continue;
    let row = existing.find((p) => p.slug === spec.slug && p.page_type === spec.page_type);
    if (!row) row = must(await api('POST', 'create_page', { title: spec.title, slug: spec.slug, page_type: spec.page_type, route_mode: spec.route_mode }), `create ${spec.slug}`).page;
    const cur = must(await api('GET', 'document', null, `&page=${row.id}`), `document ${spec.slug}`);
    const doc = cur.document;
    doc.sections = spec.doc();
    doc.seo = { ...doc.seo, title: spec.title, ...(spec.seo || {}) };
    const saved = must(await api('POST', 'save_draft', { page_id: row.id, expected_revision_id: cur.page.active_draft_revision_id, revision_kind: 'manual', document: doc, summary: 'Faisal Hossen site' }), `save ${spec.slug}`);
    const rev = saved.revision?.id ?? saved.page?.active_draft_revision_id;
    const pub = await api('POST', 'publish', { page_id: row.id, expected_revision_id: rev, summary: 'Publish' });
    console.log(spec.page_type.padEnd(15), spec.slug.padEnd(30), 'published', pub.s === 200 ? 'ok' : `FAILED ${pub.s} ${JSON.stringify(pub.j).slice(0, 200)}`);
  }
  console.log(local ? 'Done. Open http://localhost:8200/' : 'Done. Open your site’s home page.');
} finally {
  await browser.close();
}
